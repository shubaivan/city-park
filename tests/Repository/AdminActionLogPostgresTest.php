<?php

namespace App\Tests\Repository;

use App\Entity\Account;
use App\Entity\AdminActionLog;
use App\Entity\CarPlate;
use App\Entity\ExpectedResident;
use App\Entity\GuestPass;
use App\Entity\TelegramUser;
use App\Repository\AdminActionLogRepository;
use App\Service\ActionLog\ActorContext;
use App\Service\ActionLog\AdminChangeListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The field-level half of the «Журнал дій» on a real database: old → new for the fields
 * that decide who lives where and who is blocked, whoever changes them; one row for a bulk
 * change; and the page and the card history reading it back.
 *
 * Needs a migrated database whose name holds `_test`; skips without one.
 */
class AdminActionLogPostgresTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->db = $container->get('doctrine.dbal.default_connection');

        try {
            $name = (string)$this->db->getDatabase();
            $this->db->executeQuery('SELECT 1 FROM admin_action_log LIMIT 0');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No migrated test database: ' . mb_substr($e->getMessage(), 0, 80));
        }
        if (!str_contains($name, '_test')) {
            $this->markTestSkipped('Refusing to clear a database not named _test: ' . $name);
        }

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->clear();
        }
        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->executeStatement('DELETE FROM admin_action_log');
        $this->db->executeStatement("DELETE FROM guest_pass WHERE label LIKE 'AAL-%'");
        $this->db->executeStatement("DELETE FROM car_plate WHERE account_id IN (SELECT id FROM account WHERE account_number LIKE 'AAL-%')");
        $this->db->executeStatement("DELETE FROM expected_resident WHERE account_id IN (SELECT id FROM account WHERE account_number LIKE 'AAL-%')");
        $this->db->executeStatement("UPDATE telegram_user SET account_id = NULL WHERE account_id IN (SELECT id FROM account WHERE account_number LIKE 'AAL-%')");
        $this->db->executeStatement("DELETE FROM telegram_user WHERE telegram_id LIKE 'aal-%'");
        $this->db->executeStatement("DELETE FROM account WHERE account_number LIKE 'AAL-%'");
    }

    private function account(string $number, string $house, string $flat): Account
    {
        $account = (new Account())
            ->setAccountNumber('AAL-' . $number)
            ->setApartmentNumber($flat)
            ->setHouseNumber($house)
            ->setStreet('Козацька')
            ->setDebt('0')
            ->setUnitType(Account::UNIT_APARTMENT);
        $account->setIsActive(true);
        $this->em->persist($account);

        return $account;
    }

    private function user(string $tg, string $first): TelegramUser
    {
        $user = (new TelegramUser())->setTelegramId('aal-' . $tg)->setLanguageCode('uk');
        $user->setFirstName($first);
        $this->em->persist($user);

        return $user;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM admin_action_log ORDER BY id');
    }

    public function testAMoveAndARoleChangeAreOneRowWithOldAndNew(): void
    {
        static::getContainer()->get(ActorContext::class)->markCommand('test:move');
        $kv12 = $this->account('12', '91', '12');
        $kv13 = $this->account('13', '91', '13');
        $user = $this->user('1001', 'Олег');
        $user->setAccount($kv12);
        $this->em->flush();
        $this->db->executeStatement('DELETE FROM admin_action_log');

        $user->setAccount($kv13)->setRole(TelegramUser::ROLE_TENANT);
        $user->setFullName('Тестовий Олег Петрович');
        $this->em->flush();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('console:test:move', $row['actor']);
        $this->assertSame('telegram_user', $row['target_type']);
        $this->assertSame($user->getId(), $row['user_id']);
        $this->assertSame($kv13->getId(), $row['account_id']);
        $this->assertSame($kv12->getId(), $row['prev_account_id']);
        $this->assertStringContainsString('перенесено: Козацька 91, кв. 12 (AAL-12) → Козацька 91, кв. 13 (AAL-13)', $row['summary']);
        $this->assertStringContainsString('роль: не вказано → Орендар', $row['summary']);
        $this->assertStringContainsString('ПІБ: «—» → «Тестовий Олег Петрович»', $row['summary']);

        $changes = json_decode($row['changes'], true);
        $this->assertSame([$kv12->getId(), $kv13->getId()], $changes['account']);
        $this->assertSame([null, 'tenant'], $changes['role']);

        // The old flat's card sees the person who left it, the new one the person who came.
        $log = static::getContainer()->get(AdminActionLogRepository::class);
        $this->assertCount(1, $log->forAccount((int)$kv12->getId()));
        $this->assertCount(1, $log->forAccount((int)$kv13->getId()));
        $this->assertCount(1, $log->forUser((int)$user->getId()));
    }

    public function testAnOwnerGroupAndABlockAreRecordedButAResavedDebtIsNot(): void
    {
        $a = $this->account('a1', '91', '12');
        $b = $this->account('a2', '91', '13');
        $a->setDebt('1200.50');
        $this->em->flush();
        $this->db->executeStatement('DELETE FROM admin_action_log');

        $a->setOwnerGroupId((int)$a->getId());
        $b->setOwnerGroupId((int)$a->getId());
        $a->setIsActive(false);
        $a->setDebt('1200.5');
        $this->em->flush();

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $summaries = implode("\n", array_column($rows, 'summary'));
        $this->assertStringContainsString(sprintf('кв. 12 (AAL-a1): об’єднано з іншими об’єктами власника (група %d); заблоковано', $a->getId()), $summaries);
        $this->assertStringContainsString(sprintf('кв. 13 (AAL-a2): об’єднано з іншими об’єктами власника (група %d)', $a->getId()), $summaries);
        $this->assertStringNotContainsString('борг', $summaries);
    }

    public function testABulkDebtChangeIsOneRowWithTheCount(): void
    {
        $accounts = [];
        for ($i = 1; $i <= AdminChangeListener::BULK_THRESHOLD + 5; $i++) {
            $accounts[] = $this->account('b' . $i, '95', (string)$i);
        }
        $this->em->flush();
        $this->db->executeStatement('DELETE FROM admin_action_log');

        foreach ($accounts as $i => $account) {
            $account->setDebt((string)(100 + $i));
        }
        $this->em->flush();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('Масова зміна: борг — у 25 об’єктів', $rows[0]['summary']);
        $map = json_decode($rows[0]['changes'], true)['debt'];
        $this->assertCount(25, $map);
        $this->assertSame([0, 100], array_map('intval', $map[(string)$accounts[0]->getId()]));

        // …and the one object's card still shows it, with that object's own numbers.
        $id = (int)$accounts[0]->getId();
        $history = static::getContainer()->get(AdminActionLogRepository::class)->forAccount($id);
        $this->assertCount(1, $history);
        $items = static::getContainer()->get(\App\Service\ActionLog\ActionLogView::class)->present($history, $id);
        $this->assertSame('Масова зміна: борг — у 25 об’єктів · цей об’єкт: 0 → 100', $items[0]['summary']);
    }

    public function testExpectedResidentsCarsAndPassesLeaveARowEach(): void
    {
        $flat = $this->account('77', '97', '77');
        $user = $this->user('1002', 'Ольга');
        $this->em->flush();
        $this->db->executeStatement('DELETE FROM admin_action_log');

        $expected = new ExpectedResident($flat, '+380000000001', 'Тестова Ольга', 'main_admin');
        $car = (new CarPlate())->setAccount($flat)->setAddedBy($user)->setPlate('CA1234AB');
        $pass = (new GuestPass())->setAccount($flat)->setIssuedBy($user)->setLabel('AAL-сантехнік');
        $this->em->persist($expected);
        $this->em->persist($car);
        $this->em->persist($pass);
        $this->em->flush();

        $user->setAccount($flat);
        $expected->claim($user);
        $pass->revoke();
        $this->em->flush();

        $this->em->remove($car);
        $this->em->flush();

        $summaries = array_column($this->rows(), 'summary');
        $text = implode("\n", $summaries);
        $this->assertStringContainsString('додано в «Очікуємо в боті» — Тестова Ольга, +380000000001', $text);
        $this->assertStringContainsString('додано авто', $text);
        $this->assertMatchesRegularExpression('/новий пропуск №\d+ «AAL-сантехнік»/u', $text);
        $this->assertMatchesRegularExpression('/Тестова Ольга прийшов\(ла\) у бот за номером \+380000000001 — мешканець #' . $user->getId() . '/u', $text);
        $this->assertMatchesRegularExpression('/скасовано пропуск №\d+/u', $text);
        $this->assertStringContainsString('видалено авто', $text);

        $carRows = array_filter($this->rows(), static fn (array $r): bool => $r['target_type'] === 'car_plate');
        foreach ($carRows as $r) {
            $this->assertNotNull($r['target_id'], 'an inserted or deleted car keeps its id in the row');
        }
    }

    public function testThePageAndTheCardsShowTheJournal(): void
    {
        $flat = $this->account('p12', '91', '12');
        $this->em->flush();
        $this->db->insert('admin_action_log', [
            'at' => '2026-11-22 13:54:00',
            'kind' => AdminActionLog::KIND_MANUAL,
            'actor' => 'sql:ivan',
            'summary' => 'SQL вручну: тестовий запис про кв. 12',
            'target_type' => 'account',
            'target_id' => $flat->getId(),
            'account_id' => $flat->getId(),
        ]);

        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $this->client->loginUser($provider->loadUserByIdentifier('serhii'));

        $this->client->request('GET', '/admin/actions?object=91/12');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string)$this->client->getResponse()->getContent();
        $this->assertStringContainsString('SQL вручну: тестовий запис про кв. 12', $html);
        $this->assertStringContainsString('🛠 SQL вручну (ivan)', $html);
        $this->assertStringContainsString('22.11.2026', $html);

        $this->client->request('GET', '/admin/actions?q=' . urlencode('ТЕСТОВИЙ'));
        $this->assertStringContainsString('тестовий запис', (string)$this->client->getResponse()->getContent(), 'text search ignores case');

        $this->client->request('GET', '/admin/actions?object=99999999');
        $this->assertStringNotContainsString('тестовий запис', (string)$this->client->getResponse()->getContent(), 'an unknown object shows nothing, not everything');

        $this->client->request('GET', '/admin/objects/' . $flat->getId());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('📜 Історія змін', (string)$this->client->getResponse()->getContent());
        $this->assertStringContainsString('тестовий запис', (string)$this->client->getResponse()->getContent());
    }

    public function testAFailedJournalWriteDoesNotBreakTheCallersTransaction(): void
    {
        $flat = $this->account('fail', '91', '99');
        $this->em->flush();

        $this->db->executeStatement('ALTER TABLE admin_action_log RENAME TO admin_action_log_away');

        try {
            $this->em->wrapInTransaction(function () use ($flat): void {
                $flat->setIsActive(false);
                $this->em->flush();
                // The insert failed inside the transaction; a query after it must still work.
                $this->db->fetchOne('SELECT 1');
            });
        } finally {
            $this->db->executeStatement('ALTER TABLE admin_action_log_away RENAME TO admin_action_log');
        }

        $this->assertFalse((bool)$this->db->fetchOne('SELECT is_active FROM account WHERE id = ?', [$flat->getId()]));
    }
}
