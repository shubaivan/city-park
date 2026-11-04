<?php

namespace App\Service;

use App\Entity\SmsLog;
use App\Message\SmsDeliveryCheckMessage;
use App\Repository\SmsLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * What became of a sent SMS, asked of TurboSMS (message/status) and written beside the row.
 *
 * One question per job: never a sleep or a loop on the worker, which it shares with the sends, and
 * they go first (`sms_send sms_status`), so a check holds a send up by one 10 s request at most. Not
 * final means the job queues itself again, later. It reads and it records. **It never sends:** a
 * «not delivered» is a fact for a person to act on, and it does not free the number from the
 * same-day guard, since `status` stays `sent`. Nor does it touch the run: a run is finished when
 * every number was accepted or refused, and a delivery can take much longer than that.
 *
 * A check is a passenger on the send. start() cannot fail, and a row with no check is only a
 * row with no delivery status: it still reads «надіслано» and still blocks the number.
 */
class SmsDeliveryChecker
{
    /** Minutes until each next question: quick while it is likely to change, then rarely. */
    public const DELAYS_MINUTES = [1, 5, 15, 60, 240, 720];

    /**
     * After this nobody waits. The docs give no time limit for an SMS, only 86400 s for Viber's
     * ttl, so this is that day and another one to spare: a phone off over a weekend's first day.
     */
    public const GIVE_UP_AFTER_HOURS = 48;

    public function __construct(
        private SmsSender $sms,
        private SmsLogRepository $logs,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private LoggerInterface $smsLogger,
    ) {
    }

    /** Queue the first question for a row TurboSMS accepted. Whatever goes wrong stays here. */
    public function start(SmsLog $log, ?\DateTimeImmutable $now = null): void
    {
        if (!$log->isSent() || $log->getProviderId() === null || $log->getId() === null) {
            return;
        }

        $now ??= new \DateTimeImmutable();

        try {
            $this->queue(new SmsDeliveryCheckMessage(
                $log->getId(),
                0,
                $now->getTimestamp() + self::GIVE_UP_AFTER_HOURS * 3600,
            ), self::DELAYS_MINUTES[0] * 60);
        } catch (\Throwable $e) {
            $this->smsLogger->warning('sms delivery: check not queued', ['row' => $log->getId(), 'error' => $e->getMessage()]);
        }
    }

    public function check(SmsDeliveryCheckMessage $message, ?\DateTimeImmutable $now = null): void
    {
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
        }

        $started = microtime(true);
        $log = $this->logs->find($message->logId);
        if (!$log instanceof SmsLog || !$log->isSent() || $log->getProviderId() === null || $log->isDeliveryFinal()) {
            $this->smsLogger->info('sms delivery: check', ['row' => $message->logId, 'attempt' => $message->attempt, 'decision' => 'nothing to ask']);

            return;
        }

        $now ??= new \DateTimeImmutable();
        $status = $this->sms->deliveryStatus($log->getProviderId());

        if ($status !== null) {
            $log->markDelivery($status);
        }

        $decision = 'final';
        $next = null;
        if (!$log->isDeliveryFinal()) {
            $left = $message->deadline - $now->getTimestamp();
            if ($left <= 0) {
                $log->markDelivery(SmsLog::DELIVERY_NO_ANSWER);
                $decision = 'no answer by the ceiling';
            } else {
                $attempt = $message->attempt + 1;
                $delay = (self::DELAYS_MINUTES[$attempt] ?? self::DELAYS_MINUTES[count(self::DELAYS_MINUTES) - 1]) * 60;
                // Not past the deadline, so the last question comes when it says and the row is settled.
                $next = [new SmsDeliveryCheckMessage($message->logId, $attempt, $message->deadline), min($delay, $left)];
                $decision = 'ask again in ' . $next[1] . ' s';
            }
        }

        $this->em->flush();

        if ($next !== null) {
            $this->queue(...$next);
        }

        $this->smsLogger->info('sms delivery: check', [
            'row' => $log->getId(),
            'run' => $log->getRun()?->getId(),
            'phone_key' => $log->getPhoneKey(),
            'message_id' => $log->getProviderId(),
            'attempt' => $message->attempt,
            'delivery_status' => $status,
            'decision' => $decision,
            'duration_ms' => (int)((microtime(true) - $started) * 1000),
        ]);
    }

    private function queue(SmsDeliveryCheckMessage $message, int $delaySeconds): void
    {
        $this->bus->dispatch($message, [new DelayStamp($delaySeconds * 1000)]);
    }
}
