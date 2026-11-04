<?php

namespace App\Service;

use App\Command\DebtNotifySmsCommand;
use App\Entity\Account;
use App\Entity\SmsLog;
use App\Entity\SmsRun;
use App\Message\DebtSmsMessage;
use App\Repository\AccountRepository;
use App\Repository\SmsLogRepository;
use App\Repository\SmsRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * One job of a debt SMS run, in the worker: look again, write down, then send, once.
 *
 * Everything the panel checked at the tap can have changed by now, so it is checked again:
 * the hour, the balance, whether this number was written to today, and the flat itself.
 *
 * **At most once.** The row goes into `sms_log` as `sending` and is committed before the
 * request leaves. A job that finds its row already there (the worker died, Doctrine redelivered
 * it an hour later, or somebody ran messenger:failed:retry) does not send. A row still `sending`
 * becomes «невідомо, чи пішло», which blocks the number for the day. So the worst a crash can
 * do is cost that resident a reminder until tomorrow, never a second bill. The sequence_id
 * underneath is a second line, in case the row is ever lost.
 *
 * **Every row accounted for.** After a stop, the jobs left are consumed without a request, each
 * writing a row with the reason: no refusals from TurboSMS, and N + failures still reach M.
 */
class DebtSmsDelivery
{
    /**
     * Between two requests to TurboSMS. Its docs state no rate for message/send, only limits set
     * per connection (436, 437), no parallel sends where that is on (438) and 5 in 10 s and 10 in
     * 60 s to one recipient (428), which a run cannot reach. One consumer is sequential already; a
     * second apart leaves room for limits we cannot see, and on its own worker it delays nobody.
     */
    public const PAUSE_MS = 1000;

    public const REASON_STALE = SmsRun::REASON_STALE;
    public const REASON_JOB_FAILED = 'завдання не вдалося виконати, розсилку зупинено. Перевірте журнал і запустіть решту ще раз';
    public const REASON_PAID = 'борг уже погашено, не надсилаємо';
    public const REASON_NO_ACCOUNT = 'об\'єкта більше немає';
    public const REASON_PHONE_CHANGED = 'номер змінився після підтвердження, не надсилаємо';
    public const REASON_TOO_LONG = 'адреса не влазить в одну SMS';
    public const REASON_NO_BALANCE = 'TurboSMS не відповів, скільки на рахунку: розсилку зупинено';
    public const REASON_INTERRUPTED = 'обробку перервано під час надсилання';

    public function __construct(
        private DebtSmsCampaign $campaign,
        private SmsSender $sms,
        private AccountRepository $accounts,
        private SmsRunRepository $runs,
        private SmsLogRepository $logs,
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private LoggerInterface $smsLogger,
        private int $pauseMs = self::PAUSE_MS,
    ) {
    }

    public function deliver(DebtSmsMessage $message, ?\DateTimeImmutable $now = null): void
    {
        // A failed flush closes the manager, and this process lives for an hour.
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
        }

        // Shared with the console and the tap, and waited for: whoever holds it is seconds away
        // from having written the row the same-day guard below is about to read.
        $lock = $this->campaign->acquireLock(wait: true);
        if ($lock === null) {
            throw new \RuntimeException('debt sms: no lock, not sending');
        }

        try {
            $sentRequest = $this->deliverLocked($message, $now ?? new \DateTimeImmutable());
        } finally {
            $this->campaign->releaseLock($lock);
        }

        if ($sentRequest && $this->pauseMs > 0) {
            usleep($this->pauseMs * 1000);
        }
    }

    /**
     * A job fell over, usually the database. Its row may not exist, so the count will never reach
     * M: stop the run, or the button stays locked for the rest of the day. The job itself goes
     * to `failed`. If the database is still down this fails too, and tomorrow frees the button.
     */
    public function stopAfterFailure(DebtSmsMessage $message): void
    {
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
        }

        $run = $this->runs->find($message->runId);
        if ($run instanceof SmsRun && $run->isRunning()) {
            $this->stopRun($run, self::REASON_JOB_FAILED);
        }
    }

    /** @return bool whether a request went to TurboSMS */
    private function deliverLocked(DebtSmsMessage $message, \DateTimeImmutable $now): bool
    {
        $run = $this->runs->find($message->runId);
        if (!$run instanceof SmsRun) {
            $this->smsLogger->warning('debt sms: job of a run that is gone', ['run' => $message->runId]);

            return false;
        }

        $existing = $this->logs->findOneBy(['run' => $run, 'phone_key' => PhoneKey::of($message->phone)]);
        if ($existing instanceof SmsLog) {
            $this->settleRedelivered($existing, $run);

            return false;
        }

        $account = $this->accounts->find($message->accountId);
        $account = $account instanceof Account ? $account : null;

        $stop = $this->reasonToStop($run, $now);
        if ($stop !== null) {
            if ($run->isRunning()) {
                $this->stopRun($run, $stop);
            }
            $this->writeOff($message, $run, $account, $stop);

            return false;
        }

        $reason = $this->reasonToSkip($message, $account);
        if ($reason !== null) {
            $this->writeOff($message, $run, $account, $reason);

            return false;
        }

        $log = $this->sms->claim(
            $message->phone,
            (string)DebtNotifySmsCommand::text($account),
            SmsLog::PURPOSE_DEBT,
            $account,
            $run->getCreatedBy(),
            $run,
        );

        if (!$log->isSending()) {
            $this->closeIfDone($run);

            return false;
        }

        $this->sms->sendClaimed($log, sprintf('cpdebt-%d-%s', $run->getId(), $log->getPhoneKey()));

        $stopReason = SmsSender::reasonToStopRun($log);
        if ($stopReason !== null) {
            $this->stopRun($run, $stopReason);
        }
        $this->closeIfDone($run);

        return true;
    }

    /** The redelivered job: never a request. */
    private function settleRedelivered(SmsLog $existing, SmsRun $run): void
    {
        if (!$existing->isSending()) {
            return;
        }

        $existing->markFailed(SmsSender::OUTCOME_UNKNOWN . self::REASON_INTERRUPTED);
        $this->em->flush();
        $this->smsLogger->warning('debt sms: job found its row still sending', ['run' => $run->getId(), 'row' => $existing->getId()]);
        $this->closeIfDone($run);
    }

    /** What stops the rest of the run too, not only this job. */
    private function reasonToStop(SmsRun $run, \DateTimeImmutable $now): ?string
    {
        if ($run->isStopped()) {
            return (string)$run->getStopReason();
        }
        if (!$run->isOfTheDay($now)) {
            return self::REASON_STALE;
        }

        $quiet = $this->campaign->quietHoursReason($now);
        if ($quiet !== null) {
            return $quiet;
        }

        if (!$this->sms->isConfigured()) {
            return 'TurboSMS не налаштовано на сервері: розсилку зупинено';
        }

        $balance = $this->sms->balance();
        if ($balance === null) {
            return self::REASON_NO_BALANCE;
        }

        return $balance < SmsSender::PRICE_PER_PART ? DebtSmsCampaign::OUT_OF_MONEY : null;
    }

    /**
     * The flat as it is now, not as it was at the tap: a debt file can have landed in between.
     * Paid means no message. A different sum goes out as the new sum, since the text has to be
     * true when it arrives. A number no longer the flat's was never confirmed by anybody.
     */
    private function reasonToSkip(DebtSmsMessage $message, ?Account $account): ?string
    {
        if ($account === null) {
            return self::REASON_NO_ACCOUNT;
        }
        if ((float)($account->getDebt() ?? 0) < (float)AccountRepository::MIN_PUBLISHED_DEBT) {
            return self::REASON_PAID;
        }
        if (!PhoneKey::same($this->campaign->currentPhone($account), $message->phone)) {
            return self::REASON_PHONE_CHANGED;
        }

        return DebtNotifySmsCommand::text($account) === null ? self::REASON_TOO_LONG : null;
    }

    private function writeOff(DebtSmsMessage $message, SmsRun $run, ?Account $account, string $reason): void
    {
        $this->sms->writeOff($message->phone, $message->text, SmsLog::PURPOSE_DEBT, $account, $run->getCreatedBy(), $reason, $run);
        $this->closeIfDone($run);
    }

    private function stopRun(SmsRun $run, string $reason): void
    {
        $run->stop($reason);
        $this->em->flush();
        $this->smsLogger->warning('debt sms: run stopped', ['run' => $run->getId(), 'reason' => $reason]);
    }

    private function closeIfDone(SmsRun $run): void
    {
        if ($run->isRunning() && $this->runs->writtenRows($run) >= $run->getTotal()) {
            $run->finish();
            $this->em->flush();
            $this->smsLogger->info('debt sms: run finished', ['run' => $run->getId()]);
        }
    }
}
