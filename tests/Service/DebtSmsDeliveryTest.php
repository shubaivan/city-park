<?php

namespace App\Tests\Service;

use App\Entity\SmsLog;
use App\Entity\TelegramUser;
use App\Message\DebtSmsMessage;
use App\Service\DebtSmsCampaign;
use App\Service\DebtSmsDelivery;
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
            $this->statusAtRequest[] = $this->journal->rows[count($this->journal->rows) - 1]->getStatus();

            return new MockResponse(json_encode(($this->answer)($body)));
        }, 'https://api.turbosms.ua');
    }

    /** Queue a run for these debtors and hand back the campaign, jobs waiting. */
    private function queued(array $debtors, string $when = self::DAY): DebtSmsCampaign
    {
        $campaign = $this->realCampaign($debtors, $this->turbo(), $this->journal);
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

    /** TurboSMS asks for no timeout on a send; 60 s is ours, and a repeat is de-duplicated by id. */
    public function testTheRequestCarriesASequenceIdAndALongTimeout(): void
    {
        $this->queued($this->debtors([['9000', ['380671111111']]]));

        $this->drain(new \DateTimeImmutable(self::DAY));

        $this->assertSame('cpdebt-1-671111111', $this->posts[0]['sequence_id']);
        $this->assertLessThanOrEqual(40, strlen($this->posts[0]['sequence_id']));
        $this->assertSame(60.0, (float)$this->lastOptions['timeout']);
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
        $this->assertNull(SmsSender::reasonToStopRun((new SmsLog('1', 't', SmsLog::PURPOSE_DEBT))->markFailed(SmsSender::OUTCOME_UNKNOWN . 'FATAL_ERROR (9001)')));
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
