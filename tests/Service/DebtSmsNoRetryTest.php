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
 * Proof on Symfony's real Worker and retry listener, set up like the `async` transport
 * (max_retries: 3), that a failing send is not delivered again. A failure can follow a message
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

    /** On the transport the worker consumes: city-park-messenger.service runs `consume async`. */
    public function testItIsRoutedToTheTransportTheWorkerConsumes(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml');

        $this->assertSame('async', $config['framework']['messenger']['routing'][DebtSmsMessage::class]);
    }

    private function runOnce(object $job, callable $handler): InMemoryTransport
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope($job));

        $bus = new MessageBus([
            new AddBusNameStampMiddleware('messenger.bus.default'),
            new HandleMessageMiddleware(new HandlersLocator([DebtSmsMessage::class => [new HandlerDescriptor($handler)]])),
        ]);

        $locator = new class($transport, new MultiplierRetryStrategy(3, 0, 1, 0)) implements ContainerInterface {
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
