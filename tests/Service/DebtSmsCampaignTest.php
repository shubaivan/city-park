<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\SmsLog;
use App\Entity\TelegramUser;
use App\Message\DebtSmsMessage;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;
use App\Repository\SmsLogRepository;
use App\Service\DebtSmsCampaign;
use App\Service\SmsSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The panel's «Відправити СМС»: the estimate, the checks at the tap, and what happens when
 * TurboSMS runs out of money half-way. Nothing here can reach TurboSMS: SmsSender is either
 * a mock or runs on a MockHttpClient that answers everything itself.
 */
class DebtSmsCampaignTest extends TestCase
{
    use DebtSmsRig;

    private const DAY = '2026-11-02 12:00 Europe/Kyiv';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/debt-sms-' . uniqid();
        mkdir($this->projectDir . '/var', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir . '/var/debt-sms.lock');
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
    }

    /**
     * The first message goes, the second is refused and the balance is now empty: the
     * third must not be sent at all, only journalled with the reason.
     */
    public function testARunStopsWhenTheMoneyRunsOut(): void
    {
        $sms = $this->createMock(SmsSender::class);

        $sms->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            (new SmsLog('380671111111', 't', SmsLog::PURPOSE_DEBT))->markSent('a'),
            (new SmsLog('380672222222', 't', SmsLog::PURPOSE_DEBT))->markFailed('Not enough money (306)'),
        );
        $sms->method('balance')->willReturn(0.4);
        $sms->expects($this->once())->method('refuse')
            ->with('380673333333', 't', SmsLog::PURPOSE_DEBT, $this->anything(), 'панель: luda_boss', DebtSmsCampaign::OUT_OF_MONEY)
            ->willReturn((new SmsLog('380673333333', 't', SmsLog::PURPOSE_DEBT))->markFailed(DebtSmsCampaign::OUT_OF_MONEY));

        $run = $this->campaign([], $sms)->send(['recipients' => [
            ['account' => new Account(), 'phone' => '380671111111', 'text' => 't'],
            ['account' => new Account(), 'phone' => '380672222222', 'text' => 't'],
            ['account' => new Account(), 'phone' => '380673333333', 'text' => 't'],
        ]], 'панель: luda_boss');

        $this->assertSame(1, $run['sent']);
        $this->assertSame(2, $run['failed']);
        $this->assertTrue($run['outOfMoney']);
        $this->assertCount(3, $run['logs'], 'every household gets a journal row, sent or not');
    }

    /** The ceiling holds on the console too: one row reached it, the rest do not ask. */
    public function testTheConsoleLoopStopsAtTheCeiling(): void
    {
        $this->dailyLimit = 1;
        $sends = 0;
        $http = $this->turboSms(100.0, [static function () use (&$sends): array {
            $sends++;

            return ['response_code' => 0, 'response_result' => [['message_id' => 'a']]];
        }]);
        $journal = $this->journal();
        $campaign = $this->realCampaign($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']], ['7000', ['380673333333']]]), $http, $journal);

        $run = $campaign->send($campaign->plan(), 'debt:notify-sms');

        $this->assertSame(1, $sends);
        $this->assertSame(1, $run['sent']);
        $this->assertSame(2, $run['failed']);
        $this->assertSame(SmsSender::DAILY_LIMIT_REACHED, $run['logs'][1]->getError());
    }

    /** The console's accepted sends get a check like the panel's; a dry run has nothing to ask. */
    public function testTheConsoleLoopQueuesChecksButNotForADryRun(): void
    {
        $http = $this->turboSms(100.0, [static fn () => ['response_code' => 0, 'response_result' => [['message_id' => 'a']]]]);
        $debtors = $this->debtors([['9000', ['380671111111']]]);

        // Fresh journals: the fake guard reads a dry-run row as written to.
        $dry = $this->realCampaign($debtors, $http, $this->journal());
        $dry->send($dry->plan(), 'debt:notify-sms', true);
        $this->assertSame([], $this->bus->checks);

        $real = $this->realCampaign($debtors, $http, $this->journal());
        $real->send($real->plan(), 'debt:notify-sms');
        $this->assertCount(1, $this->bus->checks);
    }

    /** A wrong number is not an empty balance: the run carries on to the next household. */
    public function testOneBadNumberDoesNotStopTheRun(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            (new SmsLog('380671111111', 't', SmsLog::PURPOSE_DEBT))->markFailed('номер не схожий на телефон'),
            (new SmsLog('380672222222', 't', SmsLog::PURPOSE_DEBT))->markSent('b'),
        );
        $sms->method('balance')->willReturn(400.0);
        $sms->expects($this->never())->method('refuse');

        $run = $this->campaign([], $sms)->send(['recipients' => [
            ['account' => new Account(), 'phone' => '1', 'text' => 't'],
            ['account' => new Account(), 'phone' => '380672222222', 'text' => 't'],
        ]], 'x');

        $this->assertSame(1, $run['sent']);
        $this->assertFalse($run['outOfMoney']);
    }

    /** The figure on the page before anybody taps, and looking at it sends nothing. */
    public function testTheEstimateCountsRecipientsPartsAndCostAndSpendsNothing(): void
    {
        $sms = $this->configuredSms();
        $sms->expects($this->never())->method('send');
        $sms->expects($this->never())->method('refuse');

        $estimate = $this->campaign($this->debtors([
            ['6000', ['380671111111']],
            ['7000', ['0672222222']],
            ['5000', ['380673333333']],
            ['9000', []],
            ['4999.99', ['380674444444']],
        ]), $sms)->estimate(100.0, new \DateTimeImmutable(self::DAY));

        $this->assertSame(4, $estimate['debtors']);
        $this->assertCount(3, $estimate['recipients']);
        $this->assertSame(1, $estimate['noPhone']);
        $this->assertSame(3, $estimate['parts']);
        $this->assertSame(1, $estimate['partsPerMessage']);
        $this->assertSame(3.87, $estimate['cost']);
        $this->assertNull($estimate['blocked']);
        $this->assertSame('0672222222', $estimate['recipients'][0]['phone'], 'largest debt with a number first');
    }

    /** Whoever SmsSender would refuse is not priced: the cost is what will really be charged. */
    public function testWhoWouldBeRefusedIsNotInTheCost(): void
    {
        $sms = $this->configuredSms();
        $sms->method('alreadySentToday')->willReturnCallback(
            static fn (string $phone) => $phone === '380672222222',
        );

        $estimate = $this->campaign($this->debtors([
            ['9000', ['380671111111']],
            ['8000', ['380671111111']],
            ['7000', ['380672222222']],
            ['6000', ['48796496316']],
        ]), $sms)->estimate(100.0, new \DateTimeImmutable(self::DAY));

        $this->assertCount(1, $estimate['recipients']);
        $this->assertCount(1, $estimate['samePhone']);
        $this->assertCount(1, $estimate['alreadySent']);
        $this->assertCount(1, $estimate['invalidPhone']);
        $this->assertSame(1.29, $estimate['cost']);
    }

    /** A foreign number first in line must not cost the household its Ukrainian one. */
    public function testAUsableNumberBeatsAForeignOneInFrontOfIt(): void
    {
        $estimate = $this->campaign($this->debtors([
            ['6000', ['48796496316', '380671111111']],
        ]), $this->configuredSms())->estimate(100.0, new \DateTimeImmutable(self::DAY));

        $this->assertSame('380671111111', $estimate['recipients'][0]['phone']);
        $this->assertSame([], $estimate['invalidPhone']);
    }

    /**
     * @dataProvider blockers
     */
    public function testEveryReasonNotToSendIsSaidOutLoud(?float $balance, bool $configured, array $debtors, string $when, string $expected): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->method('isConfigured')->willReturn($configured);

        $estimate = $this->campaign($this->debtors($debtors), $sms)->estimate($balance, new \DateTimeImmutable($when));

        $this->assertStringContainsString($expected, (string)$estimate['blocked']);
    }

    /** @return array<string, array{?float, bool, array, string, string}> */
    public static function blockers(): array
    {
        $two = [['6000', ['380671111111']], ['6000', ['380672222222']]];

        return [
            'short by a little' => [2.57, true, $two, self::DAY, 'бракує 0.01 грн'],
            'balance unknown is not zero' => [null, true, $two, self::DAY, 'TurboSMS не відповів'],
            'no token' => [100.0, false, $two, self::DAY, 'не налаштовано'],
            'nobody with a number' => [100.0, true, [['6000', []]], self::DAY, 'Нікому надсилати'],
            'just after the quiet starts' => [100.0, true, $two, '2026-11-02 22:00 Europe/Kyiv', 'тихі години'],
            'just before it ends' => [100.0, true, $two, '2026-11-02 07:59 Europe/Kyiv', 'тихі години'],
            'quiet in Kyiv, whatever the server says' => [100.0, true, $two, '2026-11-02 21:00 UTC', 'тихі години'],
        ];
    }

    /** The window's edges from the other side: exactly enough money, by day, goes through. */
    public function testExactlyEnoughMoneyInTheDayIsNotBlocked(): void
    {
        $debtors = $this->debtors([['6000', ['380671111111']], ['6000', ['380672222222']]]);

        foreach (['2026-11-02 08:00 Europe/Kyiv', '2026-11-02 21:59 Europe/Kyiv'] as $when) {
            $estimate = $this->campaign($debtors, $this->configuredSms())->estimate(2.58, new \DateTimeImmutable($when));
            $this->assertNull($estimate['blocked'], $when);
        }
    }

    /**
     * The tap queues and returns: one job per number, not a request to TurboSMS beyond the
     * balance, and who pressed it is written down. What came of them is the worker's.
     */
    public function testAConfirmedRunQueuesOneJobPerNumberAndSendsNothing(): void
    {
        $http = $this->turboSms(balance: 100.0, send: []);
        $logger = $this->createMock(LoggerInterface::class);
        $logged = [];
        $logger->method('info')->willReturnCallback(static function (string $m, array $c) use (&$logged): void {
            $logged[$m] = $c;
        });

        $journal = $this->journal();
        $campaign = $this->realCampaign($this->debtors([
            ['9000', ['380671111111']],
            ['8000', ['380672222222']],
            ['7000', []],
        ]), $http, $journal, $logger);

        $result = $this->confirmEveryone($campaign, 'панель: luda_boss', new \DateTimeImmutable(self::DAY));

        $this->assertSame(DebtSmsCampaign::OUTCOME_QUEUED, $result['outcome']);
        $this->assertStringContainsString('Поставлено в чергу: 2 SMS · 2.58 грн', $result['message']);
        $this->assertSame([], $journal->rows, 'no row until the worker gets to it');
        $this->assertSame('панель: luda_boss', $logged['debt sms: run queued']['by']);
        $this->assertSame(['380671111111', '380672222222'], array_map(static fn (DebtSmsMessage $m): string => $m->phone, $this->bus->messages));
        $this->assertSame([100, 101], array_map(static fn (DebtSmsMessage $m): int => $m->accountId, $this->bus->messages));
    }

    /** The run and its jobs commit together, or the page says sent for a queue that is empty. */
    public function testTheRunAndItsJobsAreOneTransaction(): void
    {
        $campaign = $this->realCampaign($this->debtors([['9000', ['380671111111']]]), $this->turboSms(100.0, []), $this->journal());
        $this->bus->failWith = new \RuntimeException('database went away');

        $this->expectException(\RuntimeException::class);
        try {
            $this->confirmEveryone($campaign, 'a', new \DateTimeImmutable(self::DAY));
        } finally {
            $this->assertSame(1, $this->transactions);
        }
    }

    /**
     * Back, then the same form again: while the run goes the tap is refused, and afterwards
     * whoever it reached has left the list, so the old fingerprint no longer matches.
     */
    public function testTheSameFormSentTwiceSendsOnce(): void
    {
        $sends = 0;
        $reply = static function () use (&$sends) {
            $sends++;

            return ['response_code' => 0, 'response_result' => [['message_id' => 'a']]];
        };
        $http = $this->turboSms(balance: 100.0, send: array_fill(0, 10, $reply));

        $debtors = $this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']], ['7000', ['380673333333']]]);
        $campaign = $this->realCampaign($debtors, $http, $this->journal());

        $now = new \DateTimeImmutable(self::DAY);
        $estimate = $campaign->estimate(100.0, $now);
        $fingerprint = $estimate['fingerprint'];
        $ids = $this->idsOf($estimate);

        $this->assertSame(DebtSmsCampaign::OUTCOME_QUEUED, $campaign->sendConfirmed($fingerprint, $ids, 'a', $now)['outcome']);

        $during = $campaign->sendConfirmed($fingerprint, $ids, 'a', $now);
        $this->assertSame(DebtSmsCampaign::OUTCOME_BLOCKED, $during['outcome']);
        $this->assertStringContainsString('Нічого не надіслано', $during['message']);
        $this->assertCount(3, $this->bus->messages, 'the second tap queues nothing');

        $this->drain($now);
        $this->assertSame(3, $sends);

        $after = $campaign->sendConfirmed($fingerprint, $ids, 'a', $now);
        $this->assertSame(DebtSmsCampaign::OUTCOME_CHANGED, $after['outcome']);
        $this->drain($now);
        $this->assertSame(3, $sends, 'the second submit must not reach TurboSMS');
    }

    /** After a part of the run went, the old form no longer matches what is left. */
    public function testAResubmitAfterAPartialRunIsRefused(): void
    {
        $http = $this->turboSms(balance: 100.0, send: [
            static fn () => ['response_code' => 0, 'response_result' => [['message_id' => 'a']]],
            static fn () => ['response_code' => 412, 'response_status' => 'REJECTED', 'response_result' => []],
        ]);
        $campaign = $this->realCampaign(
            $this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]),
            $http,
            $this->journal(),
        );

        $now = new \DateTimeImmutable(self::DAY);
        $estimate = $campaign->estimate(100.0, $now);
        $fingerprint = $estimate['fingerprint'];
        $ids = $this->idsOf($estimate);
        $campaign->sendConfirmed($fingerprint, $ids, 'a', $now);
        $this->drain($now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_CHANGED, $campaign->sendConfirmed($fingerprint, $ids, 'a', $now)['outcome']);
    }

    /**
     * A timeout may follow a message TurboSMS accepted, so the number counts as written to:
     * the next press does not pay for it a second time.
     */
    public function testASendWithNoAnswerIsNotRetriedTheSameDay(): void
    {
        $http = $this->turboSms(balance: 100.0, send: [
            static function (): array {
                throw new TransportException('Operation timed out');
            },
        ]);
        $journal = $this->journal();
        $campaign = $this->realCampaign($this->debtors([['9000', ['380671111111']]]), $http, $journal);

        $now = new \DateTimeImmutable(self::DAY);
        $this->confirmEveryone($campaign, 'a', $now);
        $this->drain($now);

        $this->assertStringStartsWith(SmsSender::OUTCOME_UNKNOWN, (string)$journal->rows[0]->getError());
        $this->assertSame([], $campaign->estimate(100.0, $now)['recipients']);
    }

    /** Short by a hryvnia means not one SMS, rather than most of them and a surprise. */
    public function testNotEnoughMoneySendsNothingAtAll(): void
    {
        $sms = $this->configuredSms();
        $sms->method('balance')->willReturn(1.0);
        $sms->expects($this->never())->method('send');

        $campaign = $this->campaign($this->debtors([['6000', ['380671111111']], ['6000', ['380672222222']]]), $sms);
        $now = new \DateTimeImmutable(self::DAY);

        $result = $this->confirmEveryone($campaign, 'a', $now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_BLOCKED, $result['outcome']);
        $this->assertStringContainsString('Нічого не надіслано', $result['message']);
    }

    /** A form from before a debt upload: same number of people, different sums. */
    public function testAListThatMovedSinceTheConfirmationSendsNothing(): void
    {
        $sms = $this->configuredSms();
        $sms->method('balance')->willReturn(100.0);
        $sms->expects($this->never())->method('send');

        $campaign = $this->campaign($this->debtors([['6000', ['380671111111']]]), $sms);
        $result = $campaign->sendConfirmed(str_repeat('0', 64), [100], 'a', new \DateTimeImmutable(self::DAY));

        $this->assertSame(DebtSmsCampaign::OUTCOME_CHANGED, $result['outcome']);
    }

    public function testTheFingerprintFollowsThePeopleAndTheText(): void
    {
        $a = ['recipients' => [['account' => new Account(), 'phone' => '380671111111', 'text' => 'борг 6000']]];
        $sameShape = ['recipients' => [['account' => new Account(), 'phone' => '0671111111', 'text' => 'борг 6000']]];
        $otherSum = ['recipients' => [['account' => new Account(), 'phone' => '380671111111', 'text' => 'борг 9000']]];

        $this->assertSame(DebtSmsCampaign::fingerprint($a), DebtSmsCampaign::fingerprint($sameShape));
        $this->assertNotSame(DebtSmsCampaign::fingerprint($a), DebtSmsCampaign::fingerprint($otherSum));
    }

    /** A run in progress, from another admin or the console: the tap does not even look. */
    public function testASecondRunWhileOneIsInProgressIsRefused(): void
    {
        $sms = $this->configuredSms();
        $sms->expects($this->never())->method('send');
        $campaign = $this->campaign($this->debtors([['6000', ['380671111111']]]), $sms);

        $held = $campaign->acquireLock();
        $this->assertNotNull($held);
        $this->assertNull($campaign->acquireLock(), 'a second holder must be refused');

        $result = $campaign->sendConfirmed('anything', [100], 'a', new \DateTimeImmutable(self::DAY));
        $this->assertSame(DebtSmsCampaign::OUTCOME_RUNNING, $result['outcome']);

        $campaign->releaseLock($held);
        $again = $campaign->acquireLock();
        $this->assertNotNull($again, 'a finished run must not leave the button locked');
        $campaign->releaseLock($again);
    }

    /** No lock file means no way to know nobody else is sending, so nothing goes. */
    public function testALockThatCannotBeTakenSendsNothing(): void
    {
        $sms = $this->configuredSms();
        $sms->expects($this->never())->method('send');

        $campaign = new DebtSmsCampaign(
            $this->createMock(AccountRepository::class),
            $this->createMock(ExpectedResidentRepository::class),
            $sms,
            new NullLogger(),
            $this->projectDir . '/no/such/dir',
            new InMemorySmsRuns($this->journal()),
            new RecordingBus(),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(\App\Service\SmsDeliveryChecker::class),
        );

        $this->assertSame(DebtSmsCampaign::OUTCOME_RUNNING, $campaign->sendConfirmed('x', [100], 'a')['outcome']);
    }

    /** The same-day guard firing inside a run is a skip, not a failure: nothing was spent. */
    public function testAGuardedSendCountsAsSkipped(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->method('send')->willReturn(
            (new SmsLog('380671111111', 't', SmsLog::PURPOSE_DEBT))->markFailed(SmsSender::ALREADY_SENT),
        );
        $sms->expects($this->never())->method('balance');

        $run = $this->campaign([], $sms)->send(['recipients' => [
            ['account' => new Account(), 'phone' => '380671111111', 'text' => 't'],
        ]], 'x');

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 1], array_intersect_key($run, ['sent' => 1, 'failed' => 1, 'skipped' => 1]));
    }

    /** A foreign number is priced out of the run but still leaves a row saying why. */
    public function testAForeignNumberIsJournalledAsSkipped(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->expects($this->never())->method('send');
        $sms->expects($this->once())->method('refuse')->willReturn(
            (new SmsLog('48796496316', 't', SmsLog::PURPOSE_DEBT))->markFailed('x'),
        );

        $run = $this->campaign([], $sms)->send(['recipients' => [], 'invalidPhone' => [
            ['account' => new Account(), 'phone' => '48796496316', 'text' => 't'],
        ]], 'x');

        $this->assertSame(1, $run['skipped']);
        $this->assertSame(0, $run['failed']);
    }

    /** Only what was ticked goes out, is priced and gets a journal row. */
    public function testOnlyTheTickedAddressesAreSentAndPriced(): void
    {
        $journal = $this->journal();
        $sends = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sends): MockResponse {
            if (str_contains($url, '/user/balance.json')) {
                return new MockResponse(json_encode(['response_result' => ['balance' => 100.0]]));
            }
            $sends[] = json_decode($options['body'], true)['recipients'][0];

            return new MockResponse(json_encode(['response_code' => 0, 'response_result' => [['message_id' => 'a']]]));
        }, 'https://api.turbosms.ua');

        $campaign = $this->realCampaign($this->debtors([
            ['9000', ['380671111111']],
            ['8000', ['380672222222']],
            ['7000', ['380673333333']],
            ['6000', ['48796496316']],
        ]), $http, $journal);
        $now = new \DateTimeImmutable(self::DAY);

        $estimate = $campaign->estimate(100.0, $now, ['101']);
        $this->assertCount(1, $estimate['recipients']);
        $this->assertSame(1.29, $estimate['cost']);
        $this->assertSame(2, $estimate['notChosen']);
        $this->assertNull($estimate['blocked']);

        $result = $campaign->sendConfirmed($estimate['fingerprint'], ['101'], 'a', $now);
        $this->drain($now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_QUEUED, $result['outcome']);
        $this->assertSame(['380672222222'], $sends);
        $this->assertCount(1, $journal->rows, 'nobody unticked gets a row, the foreign number included');
    }

    /**
     * A hand-edited form. One id the server would refuse stops the whole run: the person
     * confirmed a list, and what is left after dropping one is not that list.
     *
     * @dataProvider notARecipient
     */
    public function testAnIdThatIsNotAllowedSendsNothingAtAll(mixed $bad): void
    {
        $sms = $this->configuredSms();
        $sms->method('balance')->willReturn(100.0);
        $sms->expects($this->never())->method('send');
        $sms->expects($this->never())->method('refuse');

        $campaign = $this->campaign($this->debtors([
            ['9000', ['380671111111']],
            ['8000', []],
            ['7000', ['48796496316']],
            ['1000', ['380674444444']],
        ]), $sms);
        $now = new \DateTimeImmutable(self::DAY);

        $fingerprint = $campaign->estimate(100.0, $now, ['100'])['fingerprint'];
        $result = $campaign->sendConfirmed($fingerprint, ['100', $bad], 'a', $now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_CHANGED, $result['outcome']);
        $this->assertStringContainsString('Нічого не надіслано', $result['message']);
    }

    public static function notARecipient(): iterable
    {
        yield 'no phone' => ['101'];
        yield 'foreign number' => ['102'];
        yield 'debt under the threshold' => ['103'];
        yield 'no such account' => ['99999'];
        yield 'an id with something after it' => ['100abc'];
        yield 'not a scalar' => [['100']];
    }

    /** Ticks swapped behind a confirmed form: the fingerprint belongs to another list. */
    public function testAFingerprintOfAnotherSelectionSendsNothing(): void
    {
        $sms = $this->configuredSms();
        $sms->method('balance')->willReturn(100.0);
        $sms->expects($this->never())->method('send');

        $campaign = $this->campaign($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]), $sms);
        $now = new \DateTimeImmutable(self::DAY);

        $fingerprint = $campaign->estimate(100.0, $now, ['100'])['fingerprint'];

        $this->assertSame(DebtSmsCampaign::OUTCOME_CHANGED, $campaign->sendConfirmed($fingerprint, ['101'], 'a', $now)['outcome']);
    }

    /** No ticks is never «everybody». */
    public function testNoSelectionSendsNothing(): void
    {
        $sms = $this->configuredSms();
        $sms->method('balance')->willReturn(100.0);
        $sms->expects($this->never())->method('send');

        $campaign = $this->campaign($this->debtors([['9000', ['380671111111']]]), $sms);
        $now = new \DateTimeImmutable(self::DAY);

        $this->assertSame('Нікого не вибрано.', $campaign->estimate(100.0, $now, [])['blocked']);

        $result = $campaign->sendConfirmed($campaign->estimate(100.0, $now)['fingerprint'], [], 'a', $now);
        $this->assertSame(DebtSmsCampaign::OUTCOME_BLOCKED, $result['outcome']);
    }

    /** The balance is held against the ticks: enough for two of three is enough for two. */
    public function testTheBalanceIsCheckedAgainstTheSelection(): void
    {
        $campaign = $this->campaign($this->debtors([
            ['9000', ['380671111111']],
            ['8000', ['380672222222']],
            ['7000', ['380673333333']],
        ]), $this->configuredSms());
        $now = new \DateTimeImmutable(self::DAY);

        $this->assertNull($campaign->estimate(2.58, $now, ['100', '101'])['blocked']);
        $this->assertStringContainsString('бракує 1.29 грн', (string)$campaign->estimate(2.58, $now, ['100', '101', '102'])['blocked']);
    }

    /** A balance short of the whole list must not grey out the card: a few can still go. */
    public function testTheCardIsUnavailableOnlyForWhatNoTickCouldChange(): void
    {
        $campaign = $this->campaign($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]), $this->configuredSms());
        $day = new \DateTimeImmutable(self::DAY);

        $this->assertNull($campaign->estimate(1.29, $day)['unavailable']);
        $this->assertNull($campaign->estimate(0.0, $day)['unavailable'], 'the sum beside the button says it is short');
        $this->assertNotNull($campaign->estimate(null, $day)['unavailable']);
        $this->assertStringContainsString('тихі години', (string)$campaign->estimate(100.0, new \DateTimeImmutable('2026-11-02 23:00 Europe/Kyiv'))['unavailable']);
    }

    /** Every debtor is a row, and a row that cannot be ticked says why. */
    public function testEveryDebtorIsARowWithTheReason(): void
    {
        $journal = $this->journal();
        $journal->rows[] = (new SmsLog('380675555555', 't', SmsLog::PURPOSE_DEBT))->markSent('x');

        $rows = $this->realCampaign($this->debtors([
            ['9000', ['380671111111']],
            ['8500', ['0671111111']],
            ['8000', []],
            ['7000', ['48796496316']],
            ['6000', ['380675555555']],
            ['1000', ['380676666666']],
        ]), $this->turboSms(100.0, []), $journal)->plan()['rows'];

        $this->assertSame(
            [null, 'той самий номер, що в іншої адреси', 'немає номера', 'номер не український', 'сьогодні вже надсилали або результат невідомий'],
            array_column($rows, 'reason'),
        );
        $this->assertSame([1, 1, 0, 1, 1], array_column($rows, 'parts'));
    }

    /** The whole list ticked and confirmed: what a test with no interest in ticks wants. */
    private function confirmEveryone(DebtSmsCampaign $campaign, string $by, \DateTimeImmutable $now): array
    {
        $estimate = $campaign->estimate(100.0, $now);

        return $campaign->sendConfirmed($estimate['fingerprint'], $this->idsOf($estimate), $by, $now);
    }

    /**
     * The search reaches every debtor, each with why they cannot be written to if they cannot;
     * the table, the figures and an unticked estimate stay the threshold's.
     */
    public function testTheSearchListsEveryDebtorAndTheTableKeepsTheThreshold(): void
    {
        $estimate = $this->campaign($this->debtors([
            ['9000', ['380671111111']],
            ['300', ['380672222222']],
            ['200', []],
            ['150', ['48796496316']],
            ['1', ['380673333333']],
            ['0.99', ['380674444444']],
            ['0', ['380675555555']],
            ['-40', ['380676666666']],
        ]), $this->configuredSms())->estimate(100.0, new \DateTimeImmutable(self::DAY));

        $this->assertSame([9000.0, 300.0, 200.0, 150.0, 1.0], array_map(
            static fn (array $r): float => (float)$r['account']->getDebt(),
            $estimate['searchable'],
        ), 'below one hryvnia is not a debt, as on the board');
        $this->assertSame([null, null, 'немає номера', 'номер не український', null], array_column($estimate['searchable'], 'reason'));

        $this->assertSame(1, $estimate['debtors']);
        $this->assertCount(1, $estimate['rows']);
        $this->assertCount(1, $estimate['recipients']);
        $this->assertSame(0, $estimate['noPhone']);
        $this->assertSame([], $estimate['invalidPhone']);
    }

    /** One plan cut in two must say what a plan at the threshold says, row by row. */
    public function testTheThresholdListIsExactlyAPlanAtTheThreshold(): void
    {
        $sms = $this->configuredSms();
        $sms->method('alreadySentToday')->willReturnCallback(static fn (string $phone) => $phone === '380675555555');

        $campaign = $this->campaign($this->debtors([
            ['9000', ['380671111111']],
            ['8000', ['380671111111']],
            ['7000', []],
            ['6000', ['48796496316']],
            ['5500', ['380675555555']],
            ['5000', ['380676666666']],
            ['4999.99', ['380676666666']],
            ['300', ['380671111111']],
            ['200', []],
        ]), $sms);

        $estimate = $campaign->estimate(100.0, new \DateTimeImmutable(self::DAY));
        $plan = $campaign->plan();

        foreach (['recipients', 'tooLong', 'noPhone', 'skippedTelegram', 'invalidPhone', 'alreadySent', 'samePhone', 'debtors', 'rows'] as $key) {
            $this->assertSame($plan[$key], $estimate[$key], $key);
        }
    }

    /** Below the threshold, picked in the search: it goes, it is priced, it is said on the way. */
    public function testAPickFromTheSearchIsSentAndPriced(): void
    {
        $sends = [];
        $journal = $this->journal();
        $campaign = $this->realCampaign($this->debtors([
            ['9000', ['380671111111']],
            ['300', ['380672222222']],
            ['200', ['380673333333']],
        ]), $this->recordingTurboSms($sends, 100.0), $journal);
        $now = new \DateTimeImmutable(self::DAY);

        $estimate = $campaign->estimate(100.0, $now, ['101']);

        $this->assertNull($estimate['blocked']);
        $this->assertSame(1.29, $estimate['cost']);
        $this->assertSame(1, $estimate['fromSearch']);
        $this->assertSame(1, $estimate['notChosen'], 'of those above the threshold, not of the whole house');

        $result = $campaign->sendConfirmed($estimate['fingerprint'], ['101'], 'a', $now);
        $this->drain($now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_QUEUED, $result['outcome']);
        $this->assertSame(['380672222222'], $sends);
        $this->assertStringContainsString('борг 300 грн', $journal->rows[0]->getText());
    }

    /** From the table and from the search at once: one address, one message. */
    public function testAnAddressChosenBothWaysGetsOneSms(): void
    {
        $sends = [];
        $campaign = $this->realCampaign($this->debtors([
            ['9000', ['380671111111']],
            ['300', ['380672222222']],
        ]), $this->recordingTurboSms($sends, 100.0), $this->journal());
        $now = new \DateTimeImmutable(self::DAY);
        $posted = ['100', '101', 100, '101'];

        $estimate = $campaign->estimate(100.0, $now, $posted);

        $this->assertSame(2, $estimate['parts']);
        $this->assertSame(0, $estimate['notChosen']);
        $this->assertSame(DebtSmsCampaign::OUTCOME_QUEUED, $campaign->sendConfirmed($estimate['fingerprint'], $posted, 'a', $now)['outcome']);
        $this->drain($now);
        $this->assertSame(['380671111111', '380672222222'], $sends);
    }

    /** Two objects on one number: one SMS a day, whichever way the second was reached for. */
    public function testASecondObjectOnTheSameNumberCannotBeAddedFromTheSearch(): void
    {
        $sends = [];
        $campaign = $this->realCampaign($this->debtors([
            ['9000', ['380671111111']],
            ['300', ['0671111111']],
        ]), $this->recordingTurboSms($sends, 100.0), $this->journal());
        $now = new \DateTimeImmutable(self::DAY);

        $estimate = $campaign->estimate(100.0, $now, ['100', '101']);
        $this->assertSame(['101'], $estimate['rejected']);
        $this->assertSame('той самий номер, що в іншої адреси', $estimate['searchable'][1]['reason']);

        $result = $campaign->sendConfirmed($estimate['fingerprint'], ['100', '101'], 'a', $now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_CHANGED, $result['outcome']);
        $this->assertSame([], $sends);
    }

    /**
     * The search does not widen what a hand-edited form can do: an id outside the plan's
     * recipients refuses the whole run, whatever else came with it.
     *
     * @dataProvider notPickable
     */
    public function testAnIdTheSearchWouldNotOfferSendsNothing(string $id): void
    {
        $sends = [];
        $campaign = $this->realCampaign($this->debtors([
            ['9000', ['380671111111']],
            ['300', []],
            ['250', ['48796496316']],
            ['0.5', ['380672222222']],
            ['0', ['380673333333']],
        ]), $this->recordingTurboSms($sends, 100.0), $this->journal());
        $now = new \DateTimeImmutable(self::DAY);

        $estimate = $campaign->estimate(100.0, $now, ['100', $id]);
        $this->assertSame([$id], $estimate['rejected']);
        $this->assertNotNull($estimate['blocked']);

        $result = $campaign->sendConfirmed($estimate['fingerprint'], ['100', $id], 'a', $now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_CHANGED, $result['outcome']);
        $this->assertSame([], $sends);
    }

    public static function notPickable(): iterable
    {
        yield 'no number' => ['101'];
        yield 'foreign number' => ['102'];
        yield 'under one hryvnia' => ['103'];
        yield 'no debt' => ['104'];
        yield 'nobody' => ['99999'];
    }

    /** A tenant is not told the flat owes: their number is not the flat's, the owner's is. */
    public function testATenantsNumberIsNotUsed(): void
    {
        [[$onlyTenant], [$both]] = $debtors = $this->debtors([['300', []], ['200', []]]);

        $tenant = static fn (string $phone) => (new TelegramUser())->setPhoneNumber($phone)->setRole(TelegramUser::ROLE_TENANT);
        $onlyTenant->getUsers()->add($tenant('380671111111'));
        $both->getUsers()->add($tenant('380672222222'));
        $both->getUsers()->add((new TelegramUser())->setPhoneNumber('380673333333'));

        $estimate = $this->campaign($debtors, $this->configuredSms())->estimate(100.0, new \DateTimeImmutable(self::DAY));

        $this->assertSame('немає номера', $estimate['searchable'][0]['reason']);
        $this->assertNull($estimate['searchable'][0]['phone']);
        $this->assertSame('380673333333', $estimate['searchable'][1]['phone']);
    }

    /**
     * The row the ОСББ wrote survives being claimed, so a person who came in on it and was then
     * marked a tenant is still on file under the object, maybe in another shape of the number.
     */
    public function testATenantsNumberIsNotUsedFromTheExpectedRowEither(): void
    {
        [[$flat]] = $debtors = $this->debtors([['300', ['0671111111']]]);
        $flat->getUsers()->add((new TelegramUser())->setPhoneNumber('380671111111')->setRole(TelegramUser::ROLE_TENANT));

        $estimate = $this->campaign($debtors, $this->configuredSms())->estimate(100.0, new \DateTimeImmutable(self::DAY));

        $this->assertSame('немає номера', $estimate['searchable'][0]['reason']);
    }

    /** Claimed by a tenant, whatever the number looks like now: theirs. By anybody else: the flat's. */
    public function testAnExpectedRowClaimedByATenantIsNotUsed(): void
    {
        $debtors = $this->debtors([
            ['300', [['phone' => '380671111111', 'claimedByRole' => TelegramUser::ROLE_TENANT]]],
            ['200', [['phone' => '380672222222', 'claimedByRole' => TelegramUser::ROLE_OWNER]]],
            ['100', [['phone' => '380673333333', 'claimedByRole' => TelegramUser::ROLE_TENANT], '380674444444']],
        ]);

        $rows = $this->campaign($debtors, $this->configuredSms())->estimate(100.0, new \DateTimeImmutable(self::DAY))['searchable'];

        $this->assertSame([null, '380672222222', '380674444444'], array_column($rows, 'phone'));
    }

    /** The owner's number the ОСББ wrote down beside a tenant in the bot is still the flat's. */
    public function testAnotherNumberBesideATenantIsStillUsed(): void
    {
        [[$flat]] = $debtors = $this->debtors([['300', ['380672222222']]]);
        $flat->getUsers()->add((new TelegramUser())->setPhoneNumber('380671111111')->setRole(TelegramUser::ROLE_TENANT));

        $rows = $this->campaign($debtors, $this->configuredSms())->estimate(100.0, new \DateTimeImmutable(self::DAY))['searchable'];

        $this->assertSame('380672222222', $rows[0]['phone']);
    }

    /** A flat's number is the same one however the residents happened to be loaded. */
    public function testTheNumberIsTheEarliestResidentsWhateverOrderTheyLoadIn(): void
    {
        $resident = function (int $id, string $phone): TelegramUser {
            $user = (new TelegramUser())->setPhoneNumber($phone);
            (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($user, $id);

            return $user;
        };

        [[$flat]] = $debtors = $this->debtors([['300', []]]);
        $flat->getUsers()->add($resident(9, '380672222222'));
        $flat->getUsers()->add($resident(4, '380671111111'));

        $estimate = $this->campaign($debtors, $this->configuredSms())->estimate(100.0, new \DateTimeImmutable(self::DAY));

        $this->assertSame('380671111111', $estimate['searchable'][0]['phone']);
    }

    /** Equal debts, one number: which object the SMS names must not depend on how they loaded. */
    public function testATieOnTheDebtDoesNotDependOnLoadOrder(): void
    {
        $debtors = $this->debtors([['300', ['380671111111']], ['300', ['380671111111']]]);
        $sms = $this->configuredSms();

        $forward = $this->campaign($debtors, $sms)->estimate(100.0, new \DateTimeImmutable(self::DAY));
        $backward = $this->campaign(array_reverse($debtors), $sms)->estimate(100.0, new \DateTimeImmutable(self::DAY));

        $this->assertSame(array_column($forward['searchable'], 'reason'), array_column($backward['searchable'], 'reason'));
        $this->assertSame($forward['searchable'][0]['account'], $backward['searchable'][0]['account']);
    }

    /** The balance has to cover the picks from the search too. */
    public function testAPickFromTheSearchCountsAgainstTheBalance(): void
    {
        $estimate = $this->campaign($this->debtors([
            ['300', ['380671111111']],
            ['200', ['380672222222']],
        ]), $this->configuredSms())->estimate(2.0, new \DateTimeImmutable(self::DAY), ['100', '101']);

        $this->assertStringContainsString('бракує 0.58 грн', (string)$estimate['blocked']);
    }

    /** Nobody above the threshold has a number, somebody below does: the card must still work. */
    public function testTheCardWorksWhenOnlyTheSearchHasSomebodyToWriteTo(): void
    {
        $now = new \DateTimeImmutable(self::DAY);

        $estimate = $this->campaign($this->debtors([['9000', []], ['300', ['380671111111']]]), $this->configuredSms())->estimate(100.0, $now);
        $this->assertNull($estimate['unavailable']);
        $this->assertSame([], $estimate['recipients']);

        $nobody = $this->campaign($this->debtors([['9000', []], ['300', []]]), $this->configuredSms())->estimate(100.0, $now);
        $this->assertSame('Нікому надсилати.', $nobody['unavailable']);
    }

    /** TurboSMS, answered from here, and the numbers it was asked to write to. */
    private function recordingTurboSms(array &$sends, float $balance): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$sends, $balance): MockResponse {
            if (str_contains($url, '/user/balance.json')) {
                return new MockResponse(json_encode(['response_result' => ['balance' => $balance]]));
            }
            $sends[] = json_decode($options['body'], true)['recipients'][0];

            return new MockResponse(json_encode(['response_code' => 0, 'response_result' => [['message_id' => 'a']]]));
        }, 'https://api.turbosms.ua');
    }

    /** @return list<int> */
    private function idsOf(array $estimate): array
    {
        return array_map(static fn (array $r): int => $r['account']->getId(), $estimate['recipients']);
    }

    private function configuredSms(): SmsSender&MockObject
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->method('isConfigured')->willReturn(true);

        return $sms;
    }

    /** TurboSMS, answered from here. An unexpected request fails the test rather than leaving. */
    private function turboSms(float $balance, array $send): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($balance, &$send): MockResponse {
            if (str_contains($url, '/user/balance.json')) {
                return new MockResponse(json_encode(['response_result' => ['balance' => $balance]]));
            }

            $next = array_shift($send);
            if ($next === null) {
                throw new \LogicException('An SMS the test did not expect: ' . $url);
            }

            return new MockResponse(json_encode($next()));
        }, 'https://api.turbosms.ua');
    }
}
