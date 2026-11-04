<?php

namespace App\Tests\Service;

use App\Message\DebtSmsMessage;
use App\MessageHandler\DebtSmsMessageHandler;
use App\Service\DebtSmsDelivery;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Yaml\Yaml;

/**
 * Proof on Symfony's real Worker and retry listener that a failing send is not delivered again,
 * both by the handler's own doing under `async`'s max_retries: 3 and by the transport's rule. A failure can follow a message
 * TurboSMS billed, so a retry can be a second bill.
 */
class DebtSmsNoRetryTest extends TestCase
{
    /** The control: the same rig does retry an ordinary failure, so the next test means something. */
    public function testTheRigRetriesAnOrdinaryFailure(): void
    {
        $job = new DebtSmsMessage(1, 1, '380671111111', 't');
        $transport = $this->runOnce($job, static function (): void {
            throw new \RuntimeException('boom');
        });

        $this->assertCount(2, $transport->getSent(), 'the original and one retry');
    }

    public function testAFailingSendIsNotDeliveredAgain(): void
    {
        $delivery = $this->createMock(DebtSmsDelivery::class);
        $delivery->expects($this->once())->method('deliver')->willThrowException(new \RuntimeException('boom'));

        $job = new DebtSmsMessage(1, 1, '380671111111', 't');
        $transport = $this->runOnce($job, new DebtSmsMessageHandler($delivery));

        $this->assertCount(1, $transport->getSent(), 'no retry was queued');
        $this->assertCount(1, $transport->getRejected(), 'it left the queue');
    }

    /** Not even a TypeError or an Error gets past as something retryable. */
    public function testAnErrorIsNotDeliveredAgainEither(): void
    {
        $delivery = $this->createMock(DebtSmsDelivery::class);
        $delivery->method('deliver')->willThrowException(new \Error('fatal-ish'));

        $transport = $this->runOnce(new DebtSmsMessage(1, 1, '380671111111', 't'), new DebtSmsMessageHandler($delivery));

        $this->assertCount(1, $transport->getSent());
    }

    /**
     * The transport's own rule, read from the real config: even a plain failure that got past
     * the handler is not delivered again. The same rig retried it above with max_retries 3.
     */
    public function testTheSmsTransportItselfDoesNotRetry(): void
    {
        $transports = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml')['framework']['messenger']['transports'];

        $transport = $this->runOnce(
            new DebtSmsMessage(1, 1, '380671111111', 't'),
            static function (): void {
                throw new \RuntimeException('boom');
            },
            $transports['sms_send']['retry_strategy']['max_retries'],
        );

        $this->assertCount(1, $transport->getSent());
        $this->assertCount(1, $transport->getRejected());
    }

    /**
     * The other way round: a check only reads, so its failure does retry, on its transport's own
     * rule read from the real config. The two rules do not leak into each other.
     */
    public function testAFailingCheckIsDeliveredAgain(): void
    {
        $transports = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml')['framework']['messenger']['transports'];
        $checker = $this->createMock(\App\Service\SmsDeliveryChecker::class);
        $checker->expects($this->once())->method('check')->willThrowException(new \RuntimeException('database went away'));

        $transport = $this->runOnce(
            new \App\Message\SmsDeliveryCheckMessage(1, 0, time() + 60),
            new \App\MessageHandler\SmsDeliveryCheckMessageHandler($checker),
            $transports['sms_status']['retry_strategy']['max_retries'],
        );

        $this->assertCount(2, $transport->getSent(), 'the original and one retry');
    }

    /**
     * One worker consuming `sms_send sms_status`: a send is taken first, whatever is waiting, on
     * Symfony's real Worker. So a check holds a send up by one request at most.
     */
    public function testASendIsTakenBeforeACheck(): void
    {
        $order = [];
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            DebtSmsMessage::class => [new HandlerDescriptor(static function (DebtSmsMessage $m) use (&$order): void { $order[] = 'send' . $m->accountId; })],
            \App\Message\SmsDeliveryCheckMessage::class => [new HandlerDescriptor(static function (\App\Message\SmsDeliveryCheckMessage $m) use (&$order, &$sends): void {
                $order[] = 'check' . $m->logId;
                $sends?->send(new Envelope(new DebtSmsMessage(1, 3, '380671111111', 't')));
                $sends = null;
            })],
        ]))]);

        $sends = new InMemoryTransport();
        $checks = new InMemoryTransport();
        $checks->send(new Envelope(new \App\Message\SmsDeliveryCheckMessage(1, 0, time() + 60)));
        $checks->send(new Envelope(new \App\Message\SmsDeliveryCheckMessage(2, 0, time() + 60)));
        $sends->send(new Envelope(new DebtSmsMessage(1, 1, '380671111111', 't')));
        $sends->send(new Envelope(new DebtSmsMessage(1, 2, '380672222222', 't')));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(5));
        $transports = array_keys(Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml')['framework']['messenger']['transports']);
        $this->assertLessThan(array_search('sms_status', $transports, true), array_search('sms_send', $transports, true));

        (new Worker(['sms_send' => $sends, 'sms_status' => $checks], $bus, $dispatcher))->run(['sleep' => 0]);

        $this->assertSame('send1', $order[0]);
        $this->assertSame('send2', $order[1]);
        $this->assertSame('check1', $order[2]);
        $this->assertContains('send3', $order);
    }

    /** Its own queue, and votes stay where they were. */
    public function testSmsHasItsOwnTransportAndVotesDoNotShareIt(): void
    {
        $messenger = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml')['framework']['messenger'];

        $this->assertSame('sms_send', $messenger['routing'][DebtSmsMessage::class]);
        $this->assertSame('sms_status', $messenger['routing'][\App\Message\SmsDeliveryCheckMessage::class]);
        $this->assertSame('async', $messenger['routing'][\App\Message\VoteBroadcastMessage::class]);
        $this->assertStringContainsString('queue_name=sms_send', $messenger['transports']['sms_send']['dsn']);
    }

    private function runOnce(object $job, callable $handler, int $maxRetries = 3): InMemoryTransport
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope($job));

        $bus = new MessageBus([
            new AddBusNameStampMiddleware('messenger.bus.default'),
            new HandleMessageMiddleware(new HandlersLocator([$job::class => [new HandlerDescriptor($handler)]])),
        ]);

        $locator = new class($transport, new MultiplierRetryStrategy($maxRetries, 0, 1, 0)) implements ContainerInterface {
            public function __construct(private object $transport, private object $strategy) {}
            public function get(string $id): object { return $id === 'retry' ? $this->strategy : $this->transport; }
            public function has(string $id): bool { return true; }
        };
        $retries = new class($locator) implements ContainerInterface {
            public function __construct(private object $inner) {}
            public function get(string $id): object { return $this->inner->get('retry'); }
            public function has(string $id): bool { return true; }
        };

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener($locator, $retries, new NullLogger(), $dispatcher));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        return $transport;
    }
}
