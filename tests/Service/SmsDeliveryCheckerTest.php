<?php

namespace App\Tests\Service;

use App\Entity\SmsLog;
use App\Message\SmsDeliveryCheckMessage;
use App\Service\SmsDeliveryChecker;
use App\Service\SmsSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Asking TurboSMS what became of a sent SMS: one question a job, a growing delay, a ceiling, and
 * never another message. Answered from here; a request to send fails the test.
 */
class SmsDeliveryCheckerTest extends TestCase
{
    use DebtSmsRig;

    private const NOW = '2026-11-02 12:00 Europe/Kyiv';

    private string $projectDir;
    private InMemorySmsJournal $journal;
    private SmsSender $sms;
    private SmsDeliveryChecker $checker;
    private int $sendRequests = 0;
    private int $statusRequests = 0;
    /** @var \Closure(string): (array|string) */
    private \Closure $status;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir();
        $this->journal = $this->journal();
        $this->bus = new RecordingBus();
        $this->runs = new InMemorySmsRuns($this->journal);
        $this->status = static fn (string $id): array => ['response_code' => 0, 'response_result' => [['message_id' => $id, 'response_code' => 0, 'status' => 'Delivered']]];

        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, '/message/status.json')) {
                $this->statusRequests++;
                $answer = ($this->status)(json_decode($options['body'], true)['messages'][0]);

                return new MockResponse(is_string($answer) ? $answer : json_encode($answer));
            }
            $this->sendRequests++;

            throw new \LogicException('A check must never send: ' . $url);
        }, 'https://api.turbosms.ua');

        $em = $this->entityManager($this->journal);
        $this->sms = new SmsSender($http, $em, $this->journal, new \Psr\Log\NullLogger(), 'mock-token', 'CityPark');
        $this->checker = $this->checker($this->sms, $this->journal, $em);
    }

    private function sentRow(?string $id = 'id-1'): SmsLog
    {
        $log = (new SmsLog('380671111111', 'т', SmsLog::PURPOSE_DEBT))->markSent($id);
        (new \ReflectionProperty(SmsLog::class, 'id'))->setValue($log, count($this->journal->rows) + 1);
        $this->journal->rows[] = $log;

        return $log;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function answering(string $status): void
    {
        $this->status = static fn (string $id): array => ['response_code' => 0, 'response_result' => [['message_id' => $id, 'response_code' => 0, 'status' => $status]]];
    }

    private function delayOf(int $index): int
    {
        return (int)($this->bus->checks[$index]->last(DelayStamp::class)->getDelay() / 1000);
    }

    public function testTheFirstCheckIsQueuedAMinuteOutWithTheCeiling(): void
    {
        $this->checker->start($log = $this->sentRow(), $this->now());

        $this->assertCount(1, $this->bus->checks);
        $job = $this->bus->checks[0]->getMessage();
        $this->assertSame([$log->getId(), 0, $this->now()->getTimestamp() + 48 * 3600], [$job->logId, $job->attempt, $job->deadline]);
        $this->assertSame(60, $this->delayOf(0));
        $this->assertSame(0, $this->statusRequests, 'it asks when the job runs, not now');
    }

    /** Nothing to ask about without an id: refused, unknown, or accepted without one. */
    public function testOnlyAnAcceptedRowWithAnIdGetsACheck(): void
    {
        $this->checker->start($this->sentRow(null), $this->now());
        $this->checker->start((new SmsLog('1', 'т', SmsLog::PURPOSE_DEBT))->markFailed('x'), $this->now());

        $this->assertSame([], $this->bus->checks);
    }

    /** A dead check must never hurt the send. */
    public function testAQueueThatFailsDoesNotReachTheSend(): void
    {
        $this->bus->failWith = new \RuntimeException('database went away');

        $this->checker->start($this->sentRow(), $this->now());

        $this->addToAssertionCount(1);
    }

    /** @dataProvider finals */
    public function testAFinalAnswerIsWrittenAndNothingIsQueued(string $status, string $state): void
    {
        $log = $this->sentRow();
        $this->answering($status);

        $this->checker->check(new SmsDeliveryCheckMessage($log->getId(), 0, $this->now()->getTimestamp() + 3600), $this->now());

        $this->assertSame($status, $log->getDeliveryStatus());
        $this->assertSame($state, $log->getDeliveryState());
        $this->assertSame([], $this->bus->checks);
        $this->assertSame(1, $this->statusRequests);
        $this->assertTrue($log->isSent(), '«sent» stays: it is what the guard and the money go by');
        $this->assertSame(0, $this->sendRequests);
    }

    public static function finals(): iterable
    {
        yield ['Delivered', SmsLog::DELIVERY_DELIVERED];
        yield ['Read', SmsLog::DELIVERY_DELIVERED];
        yield ['Expired', SmsLog::DELIVERY_UNDELIVERED];
        yield ['Undelivered', SmsLog::DELIVERY_UNDELIVERED];
        yield ['Rejected', SmsLog::DELIVERY_UNDELIVERED];
        yield ['Failed', SmsLog::DELIVERY_UNDELIVERED];
        yield ['Cancelled', SmsLog::DELIVERY_UNDELIVERED];
    }

    /** @dataProvider pendings */
    public function testAnAnswerThatIsNotFinalComesBackLater(string $status): void
    {
        $log = $this->sentRow();
        $this->answering($status);

        $this->checker->check(new SmsDeliveryCheckMessage($log->getId(), 0, $this->now()->getTimestamp() + 48 * 3600), $this->now());

        $this->assertSame(SmsLog::DELIVERY_PENDING, $log->getDeliveryState());
        $this->assertCount(1, $this->bus->checks);
        $this->assertSame(5 * 60, $this->delayOf(0));
    }

    public static function pendings(): iterable
    {
        yield ['Queued'];
        yield ['Accepted'];
        yield ['Sent'];
        yield ['Unknown'];
        yield ['SomethingNew'];
    }

    /** One question a job, 1, 5, 15, 60, 240, then every 720 minutes, and a label at the ceiling. */
    public function testTheDelaysGrowAndTheCeilingEndsIt(): void
    {
        $log = $this->sentRow();
        $this->answering('Sent');
        $start = $this->now();
        $this->checker->start($log, $start);

        $at = $start;
        $seen = [$this->delayOf(0)];
        for ($i = 0; $i < 30 && $this->bus->checks !== []; $i++) {
            $envelope = array_pop($this->bus->checks);
            $at = $at->modify('+' . (int)($envelope->last(DelayStamp::class)->getDelay() / 1000) . ' seconds');
            $this->checker->check($envelope->getMessage(), $at);
            if ($this->bus->checks !== []) {
                $seen[] = $this->delayOf(0);
            }
        }

        $this->assertSame([60, 300, 900, 3600, 14400, 43200, 43200, 43200], array_slice($seen, 0, 8));
        $this->assertSame([], $this->bus->checks);
        $this->assertSame(SmsLog::DELIVERY_UNANSWERED, $log->getDeliveryState());
        $this->assertSame('❔ статус не отримано', $log->getDeliveryLabel());
        $this->assertSame($start->getTimestamp() + 48 * 3600, $at->getTimestamp(), 'the last question comes at the ceiling, not past it');
        $this->assertLessThan(15, $this->statusRequests);
        $this->assertSame(0, $this->sendRequests);
    }

    /** @dataProvider noAnswers */
    public function testNoUsableAnswerIsAskedAgainLater(array|string $answer): void
    {
        $log = $this->sentRow();
        $this->status = static fn (): array|string => $answer;

        $this->checker->check(new SmsDeliveryCheckMessage($log->getId(), 0, $this->now()->getTimestamp() + 3600), $this->now());

        $this->assertNull($log->getDeliveryStatus());
        $this->assertCount(1, $this->bus->checks);
    }

    public static function noAnswers(): iterable
    {
        yield 'not json' => ['<html>'];
        yield 'an id it does not know' => [['response_code' => 0, 'response_result' => [['message_id' => 'id-1', 'response_code' => 414, 'response_status' => 'NOT_ALLOWED_MESSAGE_ID']]]];
        yield 'somebody else\'s' => [['response_code' => 0, 'response_result' => [['message_id' => 'other', 'status' => 'Delivered']]]];
        yield 'nothing' => [['response_code' => 105, 'response_status' => 'REQUIRED_AUTH', 'response_result' => null]];
    }

    /** A second chain, or `messenger:failed:retry`: a final row is not asked about again. */
    public function testAFinalRowIsNotAskedAboutAgain(): void
    {
        $log = $this->sentRow()->markDelivery('Delivered');

        $this->checker->check(new SmsDeliveryCheckMessage($log->getId(), 2, $this->now()->getTimestamp() + 3600), $this->now());

        $this->assertSame(0, $this->statusRequests);
        $this->assertSame([], $this->bus->checks);
    }

    public function testARowThatIsGoneEndsTheCheck(): void
    {
        $this->checker->check(new SmsDeliveryCheckMessage(999, 0, $this->now()->getTimestamp() + 3600), $this->now());

        $this->assertSame(0, $this->statusRequests);
    }
}
