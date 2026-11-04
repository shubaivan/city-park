<?php

namespace App\Tests\Service;

use App\Entity\SmsLog;
use App\Entity\TelegramUser;
use App\Message\DebtSmsMessage;
use App\Service\DebtSmsCampaign;
use App\Service\DebtSmsDelivery;
use App\Service\SmsDeliveryChecker;
use App\Service\SmsSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * One job in the worker: everything checked again at delivery, written down before the request
 * leaves, and never billed twice. TurboSMS is answered from here.
 */
class DebtSmsDeliveryTest extends TestCase
{
    use DebtSmsRig;

    private const DAY = '2026-11-02 12:00 Europe/Kyiv';

    private \Psr\Log\LoggerInterface $logs;
    private string $projectDir;
    private InMemorySmsJournal $journal;
    /** @var list<array<string, mixed>> */
    private array $posts = [];
    private int $balanceCalls = 0;
    private float $balance = 100.0;
    /** @var \Closure(array): array */
    private \Closure $answer;
    /** @var array<string, mixed> */
    private array $lastOptions = [];
    /** @var list<string> */
    private array $statusAtRequest = [];

    protected function setUp(): void
    {
        $this->logs = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{0: string, 1: array}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string)$message, $context];
            }
        };
        $this->projectDir = sys_get_temp_dir() . '/debt-sms-' . uniqid();
        mkdir($this->projectDir . '/var', 0777, true);
        $this->journal = $this->journal();
        $this->answer = static fn (array $body): array => ['response_code' => 800, 'response_status' => 'SUCCESS_MESSAGE_ACCEPTED', 'response_result' => [
            ['phone' => $body['recipients'][0], 'response_code' => 0, 'message_id' => 'id-' . $body['recipients'][0], 'response_status' => 'OK'],
        ]];
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir . '/var/debt-sms.lock');
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
    }

    private function turbo(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, '/user/balance.json')) {
                $this->balanceCalls++;

                return new MockResponse(json_encode(['response_result' => ['balance' => $this->balance]]));
            }

            $body = json_decode($options['body'], true);
            $this->posts[] = $body;
            $this->lastOptions = $options;
            // The console writes its row after the request (D6), so there may be none yet.
            $this->statusAtRequest[] = ($this->journal->rows[count($this->journal->rows) - 1] ?? null)?->getStatus() ?? 'no row';

            return new MockResponse(json_encode(($this->answer)($body)));
        }, 'https://api.turbosms.ua');
    }

    /** Queue a run for these debtors and hand back the campaign, jobs waiting. */
    private function queued(array $debtors, string $when = self::DAY): DebtSmsCampaign
    {
        $campaign = $this->realCampaign($debtors, $this->turbo(), $this->journal, $this->logs);
        $now = new \DateTimeImmutable($when);
        $estimate = $campaign->estimate(100.0, $now);
        $ids = array_map(static fn (array $r): int => $r['account']->getId(), $estimate['recipients']);
        $result = $campaign->sendConfirmed($estimate['fingerprint'], $ids, 'панель: alina', $now);
        $this->assertSame(DebtSmsCampaign::OUTCOME_QUEUED, $result['outcome'], $result['message']);
        $this->balanceCalls = 0;

        return $campaign;
    }

    private function errors(): array
    {
        return array_map(static fn (SmsLog $l): ?string => $l->getError(), $this->journal->rows);
    }

    public function testEachJobSendsOnceAndTheRunFinishes(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame(['380671111111', '380672222222'], array_map(static fn (array $b): string => $b['recipients'][0], $this->posts));
        $this->assertSame([SmsLog::STATUS_SENT, SmsLog::STATUS_SENT], array_map(static fn (SmsLog $l): string => $l->getStatus(), $this->journal->rows));
        $this->assertSame('панель: alina', $this->journal->rows[0]->getSentBy());
        $this->assertSame($this->runs->runs[1], $this->journal->rows[0]->getRun());
        $this->assertFalse($this->runs->runs[1]->isRunning());
        $this->assertFalse($this->runs->runs[1]->isStopped());
    }

    /** The whole point of the claim: by the time a request leaves, the row is there. */
    public function testTheRowIsWrittenBeforeTheRequestLeaves(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame([SmsLog::STATUS_SENDING], $this->statusAtRequest);
    }

    /** If the row cannot be written, a later attempt could not know: nothing leaves. */
    public function testARowThatCannotBeWrittenSendsNothing(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));
        $this->flushFails = new \RuntimeException('database went away');

        try {
            $this->drain(new \DateTimeImmutable(self::DAY));
            $this->fail('the job must not carry on');
        } catch (\RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        }

        $this->assertSame([], $this->posts);
    }

    /**
     * The worker died between TurboSMS accepting and the answer reaching the journal. Doctrine
     * hands the job out again an hour later: it finds its row and does not send.
     */
    public function testAJobRedeliveredAfterADeathDoesNotSendAgain(): void
    {
        $campaign = $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]));
        $now = new \DateTimeImmutable(self::DAY);
        [$first, $second] = $this->bus->messages;

        // What a death leaves behind: the row, still sending.
        $account = $campaign->plan(1)['recipients'][0]['account'];
        $sms = new SmsSender($this->turbo(), $this->entityManager($this->journal), $this->journal, new \Psr\Log\NullLogger(), 'mock-token', 'CityPark');
        $sms->claim($first->phone, $first->text, SmsLog::PURPOSE_DEBT, $account, 'x', $this->runs->runs[1]);
        $this->assertTrue($this->journal->rows[0]->isSending());

        $this->delivery->deliver($first, $now);

        $this->assertSame([], $this->posts);
        $this->assertStringStartsWith(SmsSender::OUTCOME_UNKNOWN, (string)$this->journal->rows[0]->getError());
        $this->assertTrue($this->journal->alreadySentToday('671111111', SmsLog::PURPOSE_DEBT), 'nobody writes to them again today');
        $this->assertTrue($this->runs->runs[1]->isRunning(), 'the other job is still to come');

        $this->delivery->deliver($second, $now);
        $this->assertCount(1, $this->posts);
        $this->assertFalse($this->runs->runs[1]->isRunning());
    }

    /** Redelivered after it finished, or `messenger:failed:retry` by hand: same answer. */
    public function testAJobDeliveredTwiceSendsOnce(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));
        $now = new \DateTimeImmutable(self::DAY);
        $job = $this->bus->messages[0];

        $this->delivery->deliver($job, $now);
        $this->delivery->deliver($job, $now);

        $this->assertCount(1, $this->posts);
        $this->assertCount(1, $this->journal->rows);
        $this->assertTrue($this->journal->rows[0]->isSent());
    }

    /** No answer: no retry, no second bill, and the page does not claim either way. */
    public function testATimeoutIsUnknownAndNotRetried(): void
    {
        $this->answer = static function (): array {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Operation timed out');
        };
        $this->queued($this->debtors([['9000', ['380671111111']]]));
        $now = new \DateTimeImmutable(self::DAY);
        $job = $this->bus->messages[0];

        $this->delivery->deliver($job, $now);
        $this->delivery->deliver($job, $now);

        $this->assertCount(1, $this->posts);
        $this->assertStringStartsWith(SmsSender::OUTCOME_UNKNOWN, (string)$this->journal->rows[0]->getError());
    }

    /**
     * TurboSMS hangs: minutes a job, and each maybe billed. The first one stops
     * the run, the rest write their rows with no request.
     */
    public function testTheFirstUnknownOutcomeStopsTheRun(): void
    {
        $this->answer = static function (): array {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Operation timed out');
        };
        $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']], ['7000', ['380673333333']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertCount(1, $this->posts);
        $this->assertTrue($this->runs->runs[1]->isStopped());
        $this->assertSame(SmsSender::STOPPED_ON_UNKNOWN, $this->runs->runs[1]->getStopReason());
        $this->assertCount(3, $this->journal->rows, 'the count still reaches M');
        $this->assertSame(SmsSender::STOPPED_ON_UNKNOWN, $this->journal->rows[2]->getError());
    }

    /** A row saying why not that cannot be written: the job fails where a person can see it. */
    public function testAWriteOffThatCannotBeWrittenIsNotAcknowledged(): void
    {
        $debtors = $this->debtors([['9000', ['380671111111']]]);
        $this->queued($debtors);
        $debtors[0][0]->setDebt('0');
        $this->flushFails = new \RuntimeException('database went away');

        $this->expectExceptionMessage('database went away');
        $this->drain(new \DateTimeImmutable(self::DAY));
    }

    /** The ceiling, reached by rows of every kind that may have cost money: no request, run stopped. */
    public function testTheDailyCeilingStopsTheRunWithNoRequest(): void
    {
        $this->dailyLimit = 2;
        $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']], ['7000', ['380673333333']]]));
        $this->journal->rows[] = (new SmsLog('380679999991', 't', SmsLog::PURPOSE_TEST))->markSent('other');
        $this->journal->rows[] = (new SmsLog('380679999992', 't', SmsLog::PURPOSE_ANNOUNCEMENT))->markFailed(SmsSender::OUTCOME_UNKNOWN . 'timeout');

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame([], $this->posts);
        $this->assertTrue($this->runs->runs[1]->isStopped());
        $this->assertSame(SmsSender::DAILY_LIMIT_REACHED, $this->runs->runs[1]->getStopReason());
        $this->assertSame(SmsSender::DAILY_LIMIT_REACHED, $this->journal->rows[4]->getError());
        $this->assertCount(5, $this->journal->rows, 'the count still reaches M');
    }

    /** It is reached part way: what went stays, what is left does not ask. */
    public function testTheCeilingCountsWhatTheRunItselfSent(): void
    {
        $this->dailyLimit = 1;
        $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertCount(1, $this->posts);
        $this->assertTrue($this->journal->rows[0]->isSent());
        $this->assertSame(SmsSender::DAILY_LIMIT_REACHED, $this->journal->rows[1]->getError());
    }

    /** The first real sends will be read from these lines: all of it there, no phone, no text. */
    public function testEveryJobAndSendLeavesALineWithoutThePhoneOrTheText(): void
    {
        $this->answer = static fn (array $body): array => ['response_code' => 802, 'response_status' => 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', 'response_result' => [
            ['phone' => $body['recipients'][0], 'response_code' => 406, 'message_id' => null, 'response_status' => 'NOT_ALLOWED_RECIPIENT_COUNTRY'],
        ]];
        $this->queued($this->debtors([['9000', ['380671111111']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $byName = [];
        foreach ($this->logs->lines as [$name, $context]) {
            $byName[$name] = $context;
        }
        $answer = $byName['sms answer'];
        $this->assertSame([1, 1, '671111111', 200, 802, 'refused'], [$answer['row'], $answer['run'], $answer['phone_key'], $answer['http'], $answer['code'], $answer['decision']]);
        $this->assertStringContainsString('NOT_ALLOWED_RECIPIENT_COUNTRY', $answer['raw'], 'the raw answer is kept');
        $this->assertIsInt($answer['duration_ms']);

        $job = $byName['debt sms: job'];
        $this->assertSame([1, 1, '671111111', 100.0], [$job['run'], $job['row'], $job['phone_key'], $job['balance_before']]);
        $this->assertStringContainsString('request sent: failed', $job['decision']);

        $all = json_encode($this->logs->lines, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('380671111111', $all);
        $this->assertStringNotContainsString('Просимо', $all);
    }

    /**
     * sms.log unwritable or the disk full: Monolog throws. On the panel the tap must still report
     * what it did, the job must still save its row, and a redelivered job must not send.
     */
    public function testALogThatCannotBeWrittenChangesNothingOnThePanelPath(): void
    {
        $this->logs = $this->brokenLog();
        $debtors = $this->debtors([['9000', ['380671111111']]]);
        $this->queued($debtors);

        $now = new \DateTimeImmutable(self::DAY);
        $job = array_shift($this->bus->messages);
        $this->delivery->deliver($job, $now);

        $this->assertCount(1, $this->posts);
        $this->assertTrue($this->journal->rows[0]->isSent());
        $this->assertFalse($this->runs->runs[1]->isRunning());
        $this->assertCount(1, $this->bus->checks);

        $this->delivery->deliver($job, $now);
        $this->assertCount(1, $this->posts, 'redelivered: no second request');

        $this->delivery->stopAfterFailure($job);
        $check = new SmsDeliveryChecker($this->createMock(SmsSender::class), $this->journal, $this->bus, $this->entityManager($this->journal), $this->createMock(\Doctrine\Persistence\ManagerRegistry::class), $this->brokenLog());
        $check->check($this->bus->checks[0]->getMessage(), $now);
        $this->assertCount(1, $this->posts);
    }

    /** The console and sms:test go through send(): one request, the row saved, a re-run refused. */
    public function testALogThatCannotBeWrittenChangesNothingOnTheConsolePath(): void
    {
        $campaign = $this->realCampaign($this->debtors([['9000', ['380671111111']]]), $this->turbo(), $this->journal, $this->brokenLog());

        $run = $campaign->send($plan = $campaign->plan(), 'debt:notify-sms');

        $this->assertSame(1, $run['sent']);
        $this->assertCount(1, $this->posts);
        $this->assertCount(1, $this->journal->rows);
        $this->assertTrue($this->journal->rows[0]->isSent());

        $this->assertSame([], $campaign->plan()['recipients'], 'a fresh plan leaves them out');
        $again = $campaign->send($plan, 'debt:notify-sms');
        $this->assertCount(1, $this->posts, 'and the old plan is stopped by the guard');
        $this->assertSame(1, $again['skipped']);
    }

    /** A real Monolog stream on a path it cannot open: the failure prod would see. */
    private function brokenLog(): \Monolog\Logger
    {
        return new \Monolog\Logger('sms', [new \Monolog\Handler\StreamHandler('/proc/no/such/dir/sms.log')]);
    }

    /** Accepted with an id: a check follows. Refused or unknown: nothing to ask about. */
    public function testASendThatWasAcceptedQueuesACheckAndNothingElse(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertCount(1, $this->bus->checks);
        $this->assertSame($this->journal->rows[0]->getId(), $this->bus->checks[0]->getMessage()->logId);
        $this->assertFalse($this->runs->runs[1]->isRunning(), 'the check does not hold the run open');
    }

    public function testARefusedOrUnknownSendQueuesNoCheck(): void
    {
        $this->answer = static fn (): array => ['response_code' => 802, 'response_status' => 'x', 'response_result' => [
            ['phone' => 'x', 'response_code' => 404, 'message_id' => null, 'response_status' => 'NOT_ALLOWED_NUMBER_STOPLIST'],
        ]];
        $this->queued($this->debtors([['9000', ['380671111111']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame([], $this->bus->checks);
    }

    /** The check could not be queued: the send stands, the run finishes, the job is done. */
    public function testACheckThatCannotBeQueuedDoesNotHurtTheSend(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));
        $job = array_shift($this->bus->messages);
        $this->bus->failWith = new \RuntimeException('database went away');

        $this->delivery->deliver($job, new \DateTimeImmutable(self::DAY));

        $this->assertTrue($this->journal->rows[0]->isSent());
        $this->assertFalse($this->runs->runs[1]->isRunning());
    }

    /** Died after the row and before the check: the redelivered job queues it, and does not send. */
    public function testARedeliveredJobQueuesTheCheckItNeverGot(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));
        $job = array_shift($this->bus->messages);
        $now = new \DateTimeImmutable(self::DAY);
        $this->delivery->deliver($job, $now);
        $this->bus->checks = [];

        $this->delivery->deliver($job, $now);

        $this->assertCount(1, $this->posts);
        $this->assertCount(1, $this->bus->checks);
    }

    /** Not delivered is only recorded: the number stays blocked until a person decides tomorrow. */
    public function testNotDeliveredDoesNotFreeTheNumber(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));
        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->journal->rows[0]->markDelivery('Undelivered');

        $this->assertTrue($this->journal->alreadySentToday('671111111', SmsLog::PURPOSE_DEBT));
        $this->assertCount(1, $this->posts);
    }

    /** TurboSMS asks for no timeout on a send; 120 s is ours, and a repeat is de-duplicated by id. */
    public function testTheRequestCarriesASequenceIdAndALongTimeout(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame('cpdebt-1-671111111', $this->posts[0]['sequence_id']);
        $this->assertLessThanOrEqual(40, strlen($this->posts[0]['sequence_id']));
        $this->assertSame(120.0, (float)$this->lastOptions['timeout']);
    }

    /** 22:00 with jobs left: none goes tonight, nothing goes by itself tomorrow, every row says why. */
    public function testJobsStillQueuedAtTenAtNightStopAndSayWhy(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']], ['7000', ['380673333333']]]));

        $this->delivery->deliver(array_shift($this->bus->messages), new \DateTimeImmutable('2026-11-02 21:59 Europe/Kyiv'));
        $this->drain(new \DateTimeImmutable('2026-11-02 22:00 Europe/Kyiv'));

        $this->assertCount(1, $this->posts);
        $this->assertTrue($this->runs->runs[1]->isStopped());
        $this->assertCount(3, $this->journal->rows, 'the count still reaches M');
        $this->assertStringContainsString('тихі години', (string)$this->journal->rows[2]->getError());
    }

    /** A queue that stood overnight, the worker down: not tomorrow, nobody is watching it. */
    public function testARunFromYesterdayDoesNotSend(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));

        $this->drain(new \DateTimeImmutable('2026-11-03 12:00 Europe/Kyiv'));

        $this->assertSame([], $this->posts);
        $this->assertSame([DebtSmsDelivery::REASON_STALE], $this->errors());
        $this->assertTrue($this->runs->runs[1]->isStopped());
    }

    /** Empty at delivery: no refusals from TurboSMS, one question about the balance, all accounted for. */
    public function testAnEmptyBalanceStopsTheRunWithoutARefusalPerJob(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']], ['7000', ['380673333333']]]));
        $this->balance = 0.5;

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame([], $this->posts);
        $this->assertSame(1, $this->balanceCalls);
        $this->assertSame(array_fill(0, 3, DebtSmsCampaign::OUT_OF_MONEY), $this->errors());
        $this->assertTrue($this->runs->runs[1]->isStopped());
    }

    /** The balance gave no answer: not sending blind, as at the tap. */
    public function testAnUnknownBalanceStopsTheRun(): void
    {
        $http = new MockHttpClient(static function (string $m, string $url): MockResponse {
            if (str_contains($url, '/user/balance.json')) {
                return new MockResponse('not json');
            }
            throw new \LogicException('nothing may be sent');
        }, 'https://api.turbosms.ua');
        $campaign = $this->realCampaign($this->debtors([['9000', ['380671111111']]]), $http, $this->journal);
        $now = new \DateTimeImmutable(self::DAY);
        $estimate = $campaign->estimate(100.0, $now);
        $this->runs->add($run = new \App\Entity\SmsRun('a', 1, $now));

        $this->delivery->deliver(new DebtSmsMessage(1, 100, '380671111111', 't'), $now);

        $this->assertSame([DebtSmsDelivery::REASON_NO_BALANCE], $this->errors());
        $this->assertTrue($run->isStopped());
    }

    /** It ran out during the run: the one refusal, and the rest never ask. */
    public function testARefusalForMoneyStopsTheRest(): void
    {
        $this->answer = static fn (): array => ['response_code' => 203, 'response_status' => 'REQUIRED_BALANCE', 'response_result' => null];
        $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']], ['7000', ['380673333333']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertCount(1, $this->posts);
        $this->assertSame('REQUIRED_BALANCE (203)', $this->journal->rows[0]->getError());
        $this->assertSame(DebtSmsCampaign::OUT_OF_MONEY, $this->journal->rows[1]->getError());
        $this->assertTrue($this->runs->runs[1]->isStopped());
    }

    /** A refusal about the account comes back for everybody. One about this number does not. */
    public function testAnAccountRefusalStopsAndANumberRefusalDoesNot(): void
    {
        $this->answer = static fn (): array => ['response_code' => 802, 'response_status' => 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', 'response_result' => [
            ['phone' => 'x', 'response_code' => 404, 'message_id' => null, 'response_status' => 'NOT_ALLOWED_NUMBER_STOPLIST'],
        ]];
        $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertCount(2, $this->posts);
        $this->assertSame('NOT_ALLOWED_NUMBER_STOPLIST (404)', $this->journal->rows[0]->getError());
        $this->assertFalse($this->runs->runs[1]->isStopped());

        $this->assertNotNull(SmsSender::reasonToStopRun((new SmsLog('1', 't', SmsLog::PURPOSE_DEBT))->markFailed('REQUIRED_AUTH (105)')));
        $this->assertSame(SmsSender::STOPPED_ON_UNKNOWN, SmsSender::reasonToStopRun((new SmsLog('1', 't', SmsLog::PURPOSE_DEBT))->markFailed(SmsSender::OUTCOME_UNKNOWN . 'FATAL_ERROR (9001)')));
    }

    /** The debtor paid while the job waited: no message, and the journal says why. */
    public function testAFlatThatPaidIsNotWrittenTo(): void
    {
        $debtors = $this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]);
        $this->queued($debtors);

        $debtors[0][0]->setDebt('0');
        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame(['380672222222'], array_map(static fn (array $b): string => $b['recipients'][0], $this->posts));
        $this->assertSame(DebtSmsDelivery::REASON_PAID, $this->journal->rows[0]->getError());
    }

    /** A debt file landed in between: the message says what is owed now. */
    public function testASumThatChangedGoesOutAsTheNewSum(): void
    {
        $debtors = $this->debtors([['9000', ['380671111111']]]);
        $this->queued($debtors);

        $debtors[0][0]->setDebt('6100');
        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertStringContainsString('борг 6100 грн', $this->posts[0]['sms']['text']);
        $this->assertSame('6100', $this->journal->rows[0]->getDebt());
    }

    /** Marked a tenant, or the number replaced, after the tap: nobody confirmed this person. */
    public function testANumberThatIsNoLongerTheFlatsIsNotWrittenTo(): void
    {
        $debtors = $this->debtors([['9000', ['380671111111']]]);
        $this->queued($debtors);

        $debtors[0][0]->getUsers()->add((new TelegramUser())->setPhoneNumber('380671111111')->setRole(TelegramUser::ROLE_TENANT));
        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame([], $this->posts);
        $this->assertSame([DebtSmsDelivery::REASON_PHONE_CHANGED], $this->errors());
    }

    /** The console reached this number after the tap: the guard is read at delivery. */
    public function testTheSameDayGuardIsReadAtDelivery(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));
        $this->journal->rows[] = (new SmsLog('380671111111', 't', SmsLog::PURPOSE_DEBT))->markSent('earlier');

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame([], $this->posts);
        $this->assertSame(SmsSender::ALREADY_SENT, $this->journal->rows[1]->getError());
    }

    /** The pause follows a request and only a request: a stopped run drains at once. */
    public function testThePauseFollowsARequestOnly(): void
    {
        $this->assertGreaterThanOrEqual(500, DebtSmsDelivery::PAUSE_MS);

        $campaign = $this->queued($this->debtors([['9000', ['380671111111']], ['8000', ['380672222222']]]));
        $delivery = (new \ReflectionProperty($this, 'delivery'))->getValue($this);
        $paused = new \ReflectionProperty(DebtSmsDelivery::class, 'pauseMs');
        $paused->setValue($delivery, 60);
        $now = new \DateTimeImmutable(self::DAY);

        $t = microtime(true);
        $this->delivery->deliver($this->bus->messages[0], $now);
        $this->assertGreaterThanOrEqual(0.05, microtime(true) - $t);

        $this->balance = 0.0;
        $t = microtime(true);
        $this->delivery->deliver($this->bus->messages[1], $now);
        $this->assertLessThan(0.05, microtime(true) - $t);
    }
}
