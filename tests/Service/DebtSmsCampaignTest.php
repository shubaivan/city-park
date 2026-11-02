<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\ExpectedResident;
use App\Entity\SmsLog;
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

    /** The whole path on a real SmsSender: the result, and who is written down as pressing it. */
    public function testAConfirmedRunSendsAndReportsSentFailedSkipped(): void
    {
        $http = $this->turboSms(balance: 100.0, send: [
            static fn () => ['response_code' => 0, 'response_result' => [['message_id' => 'a']]],
            static fn () => ['response_code' => 301, 'response_status' => 'REJECTED', 'response_result' => []],
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logged = [];
        $logger->method('info')->willReturnCallback(static function (string $m, array $c) use (&$logged): void {
            $logged[$m] = $c;
        });

        $campaign = $this->realCampaign($this->debtors([
            ['9000', ['380671111111']],
            ['8000', ['380672222222']],
            ['7000', []],
        ]), $http, $this->journal(), $logger);

        $now = new \DateTimeImmutable(self::DAY);
        $result = $campaign->sendConfirmed($campaign->estimate(100.0, $now)['fingerprint'], 'панель: luda_boss', $now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_SENT, $result['outcome']);
        $this->assertSame('warning', $result['flash']);
        $this->assertStringContainsString('Надіслано 1, не надіслано 1, пропущено 1', $result['message']);
        $this->assertStringContainsString('без номера 1', $result['message']);
        $this->assertSame('панель: luda_boss', $logged['debt sms: run started']['by']);
        $this->assertSame(1, $logged['debt sms: run finished']['sent']);
    }

    /**
     * Back, then the same form again: same token, same fingerprint. Whoever the first run
     * reached has left the list, so the old fingerprint no longer matches.
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
        $fingerprint = $campaign->estimate(100.0, $now)['fingerprint'];

        $this->assertSame(DebtSmsCampaign::OUTCOME_SENT, $campaign->sendConfirmed($fingerprint, 'a', $now)['outcome']);
        $this->assertSame(3, $sends);

        $second = $campaign->sendConfirmed($fingerprint, 'a', $now);

        $this->assertNotSame(DebtSmsCampaign::OUTCOME_SENT, $second['outcome']);
        $this->assertStringContainsString('Нічого не надіслано', $second['message']);
        $this->assertSame(3, $sends, 'the second submit must not reach TurboSMS');
    }

    /** After a part of the run went, the old form no longer matches what is left. */
    public function testAResubmitAfterAPartialRunIsRefused(): void
    {
        $http = $this->turboSms(balance: 100.0, send: [
            static fn () => ['response_code' => 0, 'response_result' => [['message_id' => 'a']]],
            static fn () => ['response_code' => 301, 'response_status' => 'REJECTED', 'response_result' => []],
        ]);
        $campaign = $this->realCampaign(
            $this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]),
            $http,
            $this->journal(),
        );

        $now = new \DateTimeImmutable(self::DAY);
        $fingerprint = $campaign->estimate(100.0, $now)['fingerprint'];
        $campaign->sendConfirmed($fingerprint, 'a', $now);

        $this->assertSame(DebtSmsCampaign::OUTCOME_CHANGED, $campaign->sendConfirmed($fingerprint, 'a', $now)['outcome']);
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
        $result = $campaign->sendConfirmed($campaign->estimate(100.0, $now)['fingerprint'], 'a', $now);

        $this->assertStringContainsString('не надіслано 1', $result['message']);
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

        $result = $campaign->sendConfirmed($campaign->estimate(100.0, $now)['fingerprint'], 'a', $now);

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
        $result = $campaign->sendConfirmed(str_repeat('0', 64), 'a', new \DateTimeImmutable(self::DAY));

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

        $result = $campaign->sendConfirmed('anything', 'a', new \DateTimeImmutable(self::DAY));
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
        );

        $this->assertSame(DebtSmsCampaign::OUTCOME_RUNNING, $campaign->sendConfirmed('x', 'a')['outcome']);
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

    private function configuredSms(): SmsSender&MockObject
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->method('isConfigured')->willReturn(true);

        return $sms;
    }

    /** @param list<array{0: Account, 1: list<string>}> $debtors */
    private function campaign(array $debtors, SmsSender $sms): DebtSmsCampaign
    {
        return new DebtSmsCampaign(...$this->parts($debtors), ...[$sms, new NullLogger(), $this->projectDir]);
    }

    /** @param list<array{0: Account, 1: list<string>}> $debtors */
    private function realCampaign(array $debtors, MockHttpClient $http, InMemorySmsJournal $journal, ?LoggerInterface $logger = null): DebtSmsCampaign
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $row) use ($journal): void {
            $journal->rows[] = $row;
        });

        $sms = new SmsSender($http, $em, $journal, new NullLogger(), 'mock-token', 'CityPark');

        return new DebtSmsCampaign(...$this->parts($debtors), ...[$sms, $logger ?? new NullLogger(), $this->projectDir]);
    }

    /** @return array{AccountRepository, ExpectedResidentRepository} */
    private function parts(array $debtors): array
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findAll')->willReturn(array_column($debtors, 0));

        $expected = $this->createMock(ExpectedResidentRepository::class);
        $expected->method('forAccount')->willReturnCallback(function (Account $account) use ($debtors): array {
            foreach ($debtors as [$candidate, $phones]) {
                if ($candidate === $account) {
                    return array_map(function (string $phone) {
                        $row = $this->createMock(ExpectedResident::class);
                        $row->method('getPhone')->willReturn($phone);

                        return $row;
                    }, $phones);
                }
            }

            return [];
        });

        return [$accounts, $expected];
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $rows debt and the object's phones
     *
     * @return list<array{0: Account, 1: list<string>}>
     */
    private function debtors(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => [$debt, $phones]) {
            $flat = (string)(10 + $i);
            $out[] = [
                (new Account())->setDebt($debt)->setHouseNumber('27')->setApartmentNumber($flat)->setAccountNumber('5200' . $flat),
                $phones,
            ];
        }

        return $out;
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

    private function journal(): InMemorySmsJournal
    {
        return new InMemorySmsJournal();
    }
}

/** The journal the same-day guard reads, kept in memory. */
final class InMemorySmsJournal extends SmsLogRepository
{
    /** @var list<SmsLog> */
    public array $rows = [];

    public function __construct()
    {
    }

    public function alreadySentToday(string $phoneKey, string $purpose): bool
    {
        foreach ($this->rows as $row) {
            if ($row->getPhoneKey() === $phoneKey && $row->getPurpose() === $purpose
                && (!$row->isFailed() || str_starts_with((string)$row->getError(), SmsSender::OUTCOME_UNKNOWN))) {
                return true;
            }
        }

        return false;
    }
}
