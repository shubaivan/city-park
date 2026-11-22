<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\AdminActionSubscriber;
use App\Service\ActionLog\AdminActionJournal;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every non-GET request under /admin leaves a «Журнал дій» row — without the button's own
 * code doing anything — and the row never keeps a password, a token or a file's contents.
 *
 * Runs without a database: the journal is switched to memory, so what is pinned is what
 * would be written, whatever the controller behind the request then does.
 */
class AdminActionSubscriberTest extends WebTestCase
{
    private function client(?string $login): array
    {
        $client = static::createClient();
        $client->disableReboot();

        if ($login !== null) {
            $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
            $client->loginUser($provider->loadUserByIdentifier($login));
        }

        $journal = static::getContainer()->get(AdminActionJournal::class);
        $journal->useMemory();

        return [$client, $journal];
    }

    public function testAPanelPostIsRecordedWithWhoWhatAndTheCleanedForm(): void
    {
        /** @var KernelBrowser $client */
        [$client, $journal] = $this->client('alina');

        $client->request('POST', '/admin/users/999999/role', [
            'role' => 'tenant',
            '_token' => 'csrf-value',
            'password' => 'hunter2',
            'api_token' => 'abc',
        ], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)']);

        $rows = array_values(array_filter($journal->recorded(), static fn (array $r): bool => $r['kind'] === 'request'));
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('alina', $row['actor']);
        $this->assertSame('app_admin_resident_role', $row['route']);
        $this->assertSame('POST', $row['method']);
        $this->assertSame('telegram_user', $row['target_type']);
        $this->assertSame(999999, $row['target_id']);
        $this->assertSame(999999, $row['user_id']);
        $this->assertSame($client->getResponse()->getStatusCode(), $row['status']);
        $this->assertStringStartsWith('Роль мешканця (мешканець #999999)', $row['summary']);
        $this->assertSame(['role' => 'tenant'], $row['changes']);
        $this->assertSame('Mozilla/5.0 (iPhone)', $row['user_agent']);

        $all = json_encode($journal->recorded(), \JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('hunter2', $all);
        $this->assertStringNotContainsString('csrf-value', $all);
        $this->assertStringNotContainsString('abc', $all);
    }

    /** A refusal is news too: somebody tried what their role may not do. */
    public function testARefusedPostIsRecordedAsRefused(): void
    {
        [$client, $journal] = $this->client('serhii');

        $client->request('POST', '/admin/objects/group/link', ['account_id' => '9001', 'partner_account_number' => '990002']);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $rows = $journal->recorded();
        $this->assertCount(1, $rows);
        $this->assertSame('serhii', $rows[0]['actor']);
        $this->assertSame(403, $rows[0]['status']);
        $this->assertSame('account', $rows[0]['target_type']);
        $this->assertSame(9001, $rows[0]['account_id']);
        $this->assertStringContainsString('відмовлено', $rows[0]['summary']);
    }

    public function testReadsAreNotRecorded(): void
    {
        [$client, $journal] = $this->client('alina');
        $client->request('GET', '/admin/logins');
        $client->request('POST', '/admin/users/data-table', ['draw' => 1]);
        $this->assertSame([], $journal->recorded());
    }

    /** An anonymous POST is a scanner on its way to /login; a row each would bury the real ones. */
    public function testAnonymousPostsAreNotRecorded(): void
    {
        [$anon, $journal] = $this->client(null);
        $anon->request('POST', '/admin/users/1/role', ['role' => 'owner']);
        $this->assertSame([], $journal->recorded());
    }

    public function testAnUploadIsItsNameAndSizeNeverItsContents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'debt');
        file_put_contents($path, 'SECRET-SPREADSHEET-BYTES');
        $request = Request::create('/admin/debt/upload', 'POST', ['note' => str_repeat('я', 400)], [], [
            'debt_file' => new UploadedFile($path, 'борги листопад.xlsx', null, null, true),
        ]);

        $fields = AdminActionSubscriber::cleanFields($request);

        $this->assertSame(['file' => 'борги листопад.xlsx', 'size' => 24], $fields['debt_file']);
        $this->assertStringNotContainsString('SECRET', (string)json_encode($fields));
        $this->assertSame(301, mb_strlen($fields['note']), 'long values are cut to 300 + «…»');
        @unlink($path);
    }

    public function testTheBotActorIsWhoeverPressed(): void
    {
        $this->assertSame('1001', AdminActionSubscriber::telegramSender('{"update_id":1,"message":{"from":{"id":1001},"text":"/start"}}'));
        $this->assertSame('1002', AdminActionSubscriber::telegramSender('{"update_id":2,"callback_query":{"id":"x","from":{"id":1002}}}'));
        $this->assertNull(AdminActionSubscriber::telegramSender('not json'));
        $this->assertNull(AdminActionSubscriber::telegramSender(''));
    }

    public function testOnlyChangesUnderAdminAreJournalled(): void
    {
        $this->assertFalse(AdminActionSubscriber::isJournalled(Request::create('/admin/users', 'GET')));
        $this->assertFalse(AdminActionSubscriber::isJournalled(Request::create('/login', 'POST')));
        $this->assertFalse(AdminActionSubscriber::isJournalled(Request::create('/administrator', 'POST')));
        $this->assertTrue(AdminActionSubscriber::isJournalled(Request::create('/admin/cars/remove', 'POST')));

        $table = Request::create('/admin/schedule/data-table', 'POST');
        $table->attributes->set('_route', 'admin-schedule-data-table');
        $this->assertFalse(AdminActionSubscriber::isJournalled($table));
    }
}
