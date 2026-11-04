<?php

namespace App\Tests\Repository;

use App\Entity\Account;
use App\Entity\ExpectedResident;
use App\Entity\SmsLog;
use App\Entity\SmsRun;
use App\Entity\TelegramUser;
use App\Message\DebtSmsMessage;
use App\MessageHandler\DebtSmsMessageHandler;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;
use App\Repository\SmsLogRepository;
use App\Repository\SmsRunRepository;
use App\Service\DebtSmsCampaign;
use App\Service\DebtSmsDelivery;
use App\Service\SmsSender;
use App\Tests\Service\RecordingBus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The run's queries and failures on a real database, which the in-memory journal cannot show: the
 * DQL itself, a commit before a request, and an entity manager closed by a failed flush. Failures
 * are real too, a trigger raising inside PostgreSQL. TurboSMS is answered from here.
 *
 * Needs a migrated database whose name holds `_test`. Skips without one, as on a machine where
 * the test env cannot sign in. Everything it writes goes on commit, so it clears up after itself.
 */
class DebtSmsRunPostgresTest extends KernelTestCase
{
    private const NOON = '12:00 Europe/Kyiv';

    private Connection $db;
    private EntityManagerInterface $em;
    private SmsRunRepository $runs;
    private SmsLogRepository $logs;
    private DebtSmsDelivery $delivery;
    private DebtSmsMessageHandler $handler;
    private string $lockDir;
    /** @var list<string> */
    private array $posts = [];
    /** @var list<string|false> */
    private array $statusSeenFromOutside = [];
    private \Closure $answer;
    private RecordingBus $checkBus;
    private \App\Service\SmsDeliveryChecker $checker;
    /** @var array<string, string> */
    private array $statuses = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->db = $container->get('doctrine.dbal.default_connection');

        try {
            $name = (string)$this->db->getDatabase();
            $this->db->executeQuery('SELECT 1 FROM sms_run LIMIT 0');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No migrated test database: ' . mb_substr($e->getMessage(), 0, 80));
        }
        if (!str_contains($name, '_test')) {
            $this->markTestSkipped('Refusing to clear a database not named _test: ' . $name);
        }

        $this->clear();

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->runs = $container->get(SmsRunRepository::class);
        $this->logs = $container->get(SmsLogRepository::class);
        $this->lockDir = sys_get_temp_dir() . '/debt-sms-pg-' . uniqid();
        mkdir($this->lockDir . '/var', 0777, true);
        $this->answer = static fn (array $body): array => ['response_code' => 800, 'response_status' => 'SUCCESS_MESSAGE_ACCEPTED', 'response_result' => [
            ['phone' => $body['recipients'][0], 'response_code' => 0, 'message_id' => 'id', 'response_status' => 'OK'],
        ]];

        $outside = DriverManager::getConnection($this->db->getParams());
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($outside): MockResponse {
            if (str_contains($url, '/user/balance.json')) {
                return new MockResponse(json_encode(['response_result' => ['balance' => 100.0]]));
            }
            $body = json_decode($options['body'], true);
            if (str_contains($url, '/message/status.json')) {
                return new MockResponse(json_encode(['response_code' => 0, 'response_status' => 'OK', 'response_result' => [
                    ['message_id' => $body['messages'][0], 'response_code' => 0, 'status' => $this->statuses[$body['messages'][0]] ?? 'Sent'],
                ]]));
            }
            $this->posts[] = $body['recipients'][0];
            // Another connection sees only what is committed.
            $this->statusSeenFromOutside[] = $outside->fetchOne('SELECT status FROM sms_log WHERE phone_key = ?', [substr($body['recipients'][0], -9)]);

            return new MockResponse(json_encode(($this->answer)($body)));
        }, 'https://api.turbosms.ua');

        $sms = new SmsSender($http, $this->em, $this->logs, new NullLogger(), 'mock-token', 'CityPark');
        $this->checkBus = new RecordingBus();
        $this->checker = new \App\Service\SmsDeliveryChecker($sms, $this->logs, $this->checkBus, $this->em, $container->get('doctrine'), new NullLogger());
        $campaign = new DebtSmsCampaign(
            $container->get(AccountRepository::class),
            $container->get(ExpectedResidentRepository::class),
            $sms,
            new NullLogger(),
            $this->lockDir,
            $this->runs,
            new RecordingBus(),
            $this->em,
            $this->checker,
        );
        $this->delivery = new DebtSmsDelivery(
            $campaign,
            $sms,
            $container->get(AccountRepository::class),
            $this->runs,
            $this->logs,
            $this->em,
            $container->get('doctrine'),
            new NullLogger(),
            $this->checker,
            0,
        );
        $this->handler = new DebtSmsMessageHandler($this->delivery);
    }

    protected function tearDown(): void
    {
        if (isset($this->lockDir)) {
            $this->db->executeStatement('DROP TRIGGER IF EXISTS sms_test_fail ON sms_log');
            $this->clear();
            @unlink($this->lockDir . '/var/debt-sms.lock');
            @rmdir($this->lockDir . '/var');
            @rmdir($this->lockDir);
        }
        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->executeStatement('DELETE FROM sms_log');
        $this->db->executeStatement('DELETE FROM sms_run');
        $this->db->executeStatement('DELETE FROM expected_resident');
        $this->db->executeStatement("UPDATE telegram_user SET account_id = NULL WHERE account_id IN (SELECT id FROM account WHERE account_number LIKE 'PG-%')");
        $this->db->executeStatement("DELETE FROM telegram_user WHERE telegram_id LIKE 'pg-%'");
        $this->db->executeStatement("DELETE FROM account WHERE account_number LIKE 'PG-%'");
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today ' . self::NOON);
    }

    private function newRun(int $total, ?\DateTimeImmutable $when = null): SmsRun
    {
        $run = new SmsRun('панель: pg', $total, $when ?? $this->today());
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function account(string $house, string $flat, string $debt, ?string $phone): Account
    {
        $account = (new Account())->setAccountNumber('PG-' . $house . '-' . $flat)->setHouseNumber($house)->setApartmentNumber($flat)->setStreet('Козацька')->setDebt($debt);
        $this->em->persist($account);
        if ($phone !== null) {
            $this->em->persist(new ExpectedResident($account, $phone));
        }
        $this->em->flush();

        return $account;
    }

    private function row(string $phone, ?SmsRun $run, string $status, ?string $error = null): SmsLog
    {
        $log = (new SmsLog($phone, 'т', SmsLog::PURPOSE_DEBT))->setRun($run);
        match ($status) {
            SmsLog::STATUS_SENT => $log->markSent('id'),
            SmsLog::STATUS_SENDING => $log->markSending(),
            default => $log->markFailed((string)$error),
        };
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    private function raiseOn(string $event): void
    {
        $this->db->executeStatement("CREATE OR REPLACE FUNCTION sms_test_fail() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'database went away'; END; \$\$ LANGUAGE plpgsql");
        $this->db->executeStatement("CREATE TRIGGER sms_test_fail BEFORE $event ON sms_log FOR EACH ROW EXECUTE FUNCTION sms_test_fail()");
    }

    private function dropTrigger(): void
    {
        $this->db->executeStatement('DROP TRIGGER IF EXISTS sms_test_fail ON sms_log');
    }

    private function runStatus(int $id): string
    {
        return (string)$this->db->fetchOne('SELECT status FROM sms_run WHERE id = ?', [$id]);
    }

    public function testTheStatusCountsEachStateFromTheRows(): void
    {
        $run = $this->newRun(6);
        $this->row('380671111111', $run, SmsLog::STATUS_SENT);
        $this->row('380672222222', $run, SmsLog::STATUS_FAILED, 'NOT_ALLOWED_NUMBER_STOPLIST (404)');
        $this->row('380673333333', $run, SmsLog::STATUS_FAILED, SmsSender::OUTCOME_UNKNOWN . 'timeout');
        $this->row('380674444444', $run, SmsLog::STATUS_SENDING);

        $status = $this->runs->latestStatus(new \DateTimeImmutable());

        $this->assertSame([1, 1, 1, 1, 2, false], [$status['sent'], $status['failed'], $status['unknown'], $status['sending'], $status['queued'], $status['stalled']]);
        $this->assertSame($run, $status['run']);
    }

    /** Yesterday's: the card does not say «йде», the page does not reload, the button is free. */
    public function testARunFromAnotherDayIsNotShownAndHoldsNothing(): void
    {
        $this->newRun(3, new \DateTimeImmutable('-1 day ' . self::NOON));
        $now = new \DateTimeImmutable();

        $this->assertNull($this->runs->latestStatus($now));
        $this->assertNull($this->runs->findActive($now));
    }

    public function testTheStaleRunIsClosedAndTodaysIsNot(): void
    {
        $old = $this->newRun(3, new \DateTimeImmutable('-1 day 23:50 Europe/Kyiv'));
        $today = $this->newRun(3, new \DateTimeImmutable('today 00:05 Europe/Kyiv'));

        $this->assertSame(1, $this->runs->closeStale(new \DateTimeImmutable()));

        $this->assertSame(SmsRun::STATUS_STOPPED, $this->runStatus($old->getId()));
        $this->assertSame(SmsRun::STATUS_RUNNING, $this->runStatus($today->getId()));
        $this->assertSame(SmsRun::REASON_STALE, $this->db->fetchOne('SELECT stop_reason FROM sms_run WHERE id = ?', [$old->getId()]));
    }

    /** Sent is what was confirmed; what may have been billed is counted beside it. */
    public function testTheMonthCountsUnknownApart(): void
    {
        $this->row('380671111111', null, SmsLog::STATUS_SENT);
        $this->row('380672222222', null, SmsLog::STATUS_FAILED, 'NOT_ALLOWED_NUMBER_STOPLIST (404)');
        $this->row('380673333333', null, SmsLog::STATUS_FAILED, SmsSender::OUTCOME_UNKNOWN . 'timeout');
        $this->row('380674444444', null, SmsLog::STATUS_SENDING);

        $this->assertSame(
            ['sent' => 1, 'failed' => 1, 'parts' => 1, 'unknown' => 2, 'unknownParts' => 2],
            $this->logs->summarySince(new \DateTime('-1 day')),
        );
    }

    public function testTheGuardBlocksSentSendingAndUnknownButNotARefusal(): void
    {
        $this->row('380671111111', null, SmsLog::STATUS_SENT);
        $this->row('380672222222', null, SmsLog::STATUS_SENDING);
        $this->row('380673333333', null, SmsLog::STATUS_FAILED, SmsSender::OUTCOME_UNKNOWN . 'timeout');
        $this->row('380674444444', null, SmsLog::STATUS_FAILED, 'NOT_ALLOWED_NUMBER_STOPLIST (404)');

        foreach (['671111111' => true, '672222222' => true, '673333333' => true, '674444444' => false] as $key => $blocked) {
            $this->assertSame($blocked, $this->logs->alreadySentToday((string)$key, SmsLog::PURPOSE_DEBT), (string)$key);
        }
    }

    public function testTheDatabaseHoldsOneRowPerNumberPerRun(): void
    {
        $run = $this->newRun(2);
        $this->row('380671111111', $run, SmsLog::STATUS_SENT);
        $this->row('380671111111', null, SmsLog::STATUS_SENT);
        $this->row('380671111111', null, SmsLog::STATUS_SENT);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->row('0671111111', $run, SmsLog::STATUS_SENT);
    }

    public function testPhonesCarryWhoClaimedThem(): void
    {
        $this->account('27', '63', '300', '380671111111');
        $flat = $this->account('27', '64', '300', '380672222222');
        $tenant = (new TelegramUser())->setPhoneNumber('380672222222')->setRole(TelegramUser::ROLE_TENANT);
        $tenant->setTelegramId('pg-1')->setLanguageCode('uk');
        $this->em->persist($tenant);
        $this->em->flush();
        $this->db->executeStatement('UPDATE expected_resident SET claimed_by_id = ? WHERE account_id = ?', [$tenant->getId(), $flat->getId()]);

        $phones = self::getContainer()->get(ExpectedResidentRepository::class)->phonesByAccount();

        $this->assertSame([['phone' => '380672222222', 'claimedByRole' => TelegramUser::ROLE_TENANT]], $phones[$flat->getId()]);
    }

    /** The row is committed when the request leaves: another connection can already read it. */
    public function testTheRowIsCommittedBeforeTheRequestLeaves(): void
    {
        $flat = $this->account('27', '63', '300', '380671111111');
        $run = $this->newRun(1);

        ($this->handler)(new DebtSmsMessage($run->getId(), $flat->getId(), '380671111111', 'т'));

        $this->assertSame([SmsLog::STATUS_SENDING], $this->statusSeenFromOutside);
        $this->assertSame(SmsRun::STATUS_FINISHED, $this->runStatus($run->getId()));
        $this->assertSame(SmsLog::STATUS_SENT, $this->db->fetchOne('SELECT status FROM sms_log'));
    }

    /** The row cannot be written: no request, not acknowledged, and the button is free again. */
    public function testARowThatCannotBeWrittenSendsNothingAndStopsTheRun(): void
    {
        $flat = $this->account('27', '63', '300', '380671111111');
        $run = $this->newRun(2);
        $this->raiseOn('INSERT');

        try {
            ($this->handler)(new DebtSmsMessage($run->getId(), $flat->getId(), '380671111111', 'т'));
            $this->fail('the job must not be acknowledged');
        } catch (UnrecoverableMessageHandlingException) {
        }

        $this->assertSame([], $this->posts);
        $this->assertSame(SmsRun::STATUS_STOPPED, $this->runStatus($run->getId()));
        $this->assertNull($this->runs->findActive(new \DateTimeImmutable()));
    }

    /** A write-off used to be swallowed: acknowledged with no row and no failed entry. */
    public function testAWriteOffThatCannotBeWrittenIsNotAcknowledged(): void
    {
        $flat = $this->account('27', '63', '0', '380671111111');
        $run = $this->newRun(1);
        $this->raiseOn('INSERT');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($this->handler)(new DebtSmsMessage($run->getId(), $flat->getId(), '380671111111', 'т'));
    }

    /**
     * TurboSMS accepted, the journal cannot be updated, on the last job. The flush closes the
     * manager, and the handler still gets the run stopped through a fresh one. Redelivered once
     * the database is back, the job finds its row: no second request, and the row says so.
     */
    public function testAnAnswerThatCannotBeWrittenStopsTheRunAndIsNotSentAgain(): void
    {
        $flat = $this->account('27', '63', '300', '380671111111');
        $run = $this->newRun(1);
        $job = new DebtSmsMessage($run->getId(), $flat->getId(), '380671111111', 'т');
        $this->raiseOn('UPDATE');

        try {
            ($this->handler)($job);
            $this->fail('the job must not be acknowledged');
        } catch (UnrecoverableMessageHandlingException) {
        }

        $this->assertCount(1, $this->posts);
        $this->assertSame(SmsLog::STATUS_SENDING, $this->db->fetchOne('SELECT status FROM sms_log'));
        $this->assertSame(SmsRun::STATUS_STOPPED, $this->runStatus($run->getId()));

        $this->dropTrigger();
        ($this->handler)($job);

        $this->assertCount(1, $this->posts, 'never a second bill');
        $this->assertStringStartsWith(SmsSender::OUTCOME_UNKNOWN, (string)$this->db->fetchOne('SELECT error FROM sms_log'));
    }

    /** After a closed manager the worker goes on: the next job of another run still works. */
    public function testTheNextJobWorksAfterAFailedOne(): void
    {
        $first = $this->account('27', '63', '300', '380671111111');
        $second = $this->account('27', '64', '300', '380672222222');
        $failed = $this->newRun(1);
        $failedId = $failed->getId();
        $firstId = $first->getId();
        $secondId = $second->getId();
        $next = $this->newRun(1);
        $nextId = $next->getId();
        $this->raiseOn('INSERT');

        try {
            ($this->handler)(new DebtSmsMessage($failedId, $firstId, '380671111111', 'т'));
        } catch (UnrecoverableMessageHandlingException) {
        }
        $this->dropTrigger();

        ($this->handler)(new DebtSmsMessage($nextId, $secondId, '380672222222', 'т'));

        $this->assertSame(['380672222222'], $this->posts);
        $this->assertSame(SmsRun::STATUS_FINISHED, $this->runStatus($nextId));
    }

    /** Accepted, then delivered: the row and the card, from real rows, with a real query. */
    public function testDeliveryIsCountedApartFromSentAndLeavesTheRunAlone(): void
    {
        $flat = $this->account('27', '63', '300', '380671111111');
        $other = $this->account('27', '64', '300', '380672222222');
        $run = $this->newRun(2);
        ($this->handler)(new DebtSmsMessage($run->getId(), $flat->getId(), '380671111111', 'т'));
        ($this->handler)(new DebtSmsMessage($run->getId(), $other->getId(), '380672222222', 'т'));

        $this->assertSame(SmsRun::STATUS_FINISHED, $this->runStatus($run->getId()));
        $this->assertCount(2, $this->checkBus->checks);

        $status = $this->runs->latestStatus(new \DateTimeImmutable());
        $this->assertSame([2, 0, 2], [$status['sent'], $status['delivered'], $status['awaiting']]);

        $this->statuses['id'] = 'Sent';
        $this->checker->check($this->checkBus->checks[0]->getMessage());
        $this->assertSame('Sent', $this->db->fetchOne('SELECT delivery_status FROM sms_log WHERE phone_key = ?', ['671111111']));
        $this->assertCount(3, $this->checkBus->checks, 'not final: it queued itself');

        $this->statuses['id'] = 'Delivered';
        $this->em->clear();
        $this->checker->check($this->checkBus->checks[2]->getMessage());
        $this->statuses['id'] = 'Undelivered';
        $this->em->clear();
        $this->checker->check($this->checkBus->checks[1]->getMessage());

        $this->assertCount(3, $this->checkBus->checks, 'final: nothing further queued');

        $status = $this->runs->latestStatus(new \DateTimeImmutable());
        $this->assertSame([2, 2, 1, 1, 0, 0], [$status['total'], $status['sent'], $status['delivered'], $status['undelivered'], $status['awaiting'], $status['unanswered']]);
        $this->assertSame(SmsRun::STATUS_FINISHED, $this->runStatus($run->getId()));
        $this->assertCount(2, $this->posts, 'nothing was sent again');

        // Not delivered does not free the number.
        $this->assertTrue($this->logs->alreadySentToday('672222222', SmsLog::PURPOSE_DEBT));
    }

    /** Older rows: the migration leaves them, and nobody claims anything about them. */
    public function testARowNeverAskedAboutHasNoDeliveryState(): void
    {
        $log = $this->row('380671111111', null, SmsLog::STATUS_SENT);
        $this->em->clear();

        $found = $this->logs->find($log->getId());
        $this->assertNull($found->getDeliveryStatus());
        $this->assertNull($found->getDeliveryLabel());
    }

    /** A check due long ago and still waiting: the worker is down, and the card says so. */
    public function testAnOverdueCheckIsNoticedAndAFutureOneIsNot(): void
    {
        $flat = $this->account('27', '63', '300', '380671111111');
        $run = $this->newRun(1);
        ($this->handler)(new DebtSmsMessage($run->getId(), $flat->getId(), '380671111111', 'т'));

        $this->db->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', ['sms_status']);
        $insert = "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('x', 'x', 'sms_status', (now() at time zone 'UTC') - interval '3 hours', (now() at time zone 'UTC') + make_interval(mins => ?))";

        try {
            $this->db->executeStatement($insert, [30]);
            $this->assertFalse($this->runs->latestStatus(new \DateTimeImmutable())['checksStalled'], 'due later: fine');

            $this->db->executeStatement($insert, [-30]);
            $this->assertTrue($this->runs->latestStatus(new \DateTimeImmutable())['checksStalled']);
        } finally {
            $this->db->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', ['sms_status']);
        }
    }

    /** What the ceiling counts, on the real query: what may have cost money today, and only that. */
    public function testTheCeilingCountsOnlyWhatMayHaveCostMoneyToday(): void
    {
        $this->row('380671111111', null, SmsLog::STATUS_SENT);
        $this->row('380672222222', null, SmsLog::STATUS_SENDING);
        $this->row('380673333333', null, SmsLog::STATUS_FAILED, SmsSender::OUTCOME_UNKNOWN . 'timeout');
        $this->row('380674444444', null, SmsLog::STATUS_FAILED, 'NOT_ALLOWED_NUMBER_STOPLIST (404)');
        $this->row('380675555555', null, SmsLog::STATUS_FAILED, SmsSender::DAILY_LIMIT_REACHED);
        $yesterday = $this->row('380676666666', null, SmsLog::STATUS_SENT);
        $this->db->executeStatement("UPDATE sms_log SET created_at = created_at - interval '1 day' WHERE id = ?", [$yesterday->getId()]);

        $this->assertSame(3, $this->logs->costlyToday());
    }
}
