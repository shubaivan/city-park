<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\PavilionPhoto;
use App\Entity\PhotoUploadRequest;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\PavilionPhotoRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\ScheduledSetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PavilionPhotoService
{
    /**
     * Window the cron looks back when materializing sessions into PhotoUploadRequests.
     * Anything older than this won't trigger new requests retroactively.
     */
    public const LOOKBACK_HOURS = 26;

    public const REMINDER_OFFSETS_MIN = [5, 15];
    public const BLOCK_AFTER_MIN = 20;

    /** Reminders that would fire between 23:00 and 09:00 Kyiv time are deferred to 09:00. */
    public const NIGHT_START_HOUR = 23;
    public const NIGHT_END_HOUR = 9;

    /**
     * Sessions whose end time is before this cutoff are grandfathered — no photo
     * obligation, no reminders, no blocking. Bookings created before the feature
     * shipped are treated as "done".
     */
    public const OBLIGATION_START_AT = '2026-05-24 00:00:00';

    private string $uploadDir;
    private string $publicPathPrefix = '/uploads/pavilion-photos';

    public function __construct(
        string $projectDir,
        private ScheduledSetRepository $scheduledSetRepository,
        private PavilionPhotoRepository $photoRepository,
        private PhotoUploadRequestRepository $requestRepository,
        private EntityManagerInterface $em,
        private DebtPolicy $debtPolicy,
    ) {
        $this->uploadDir = rtrim($projectDir, '/') . '/public/uploads/pavilion-photos';
    }

    /**
     * Group an account's past-or-ending bookings into consecutive sessions per pavilion.
     *
     * @return array<int, array{pavilion:int, start:\DateTime, end:\DateTime, sets:ScheduledSet[]}>
     */
    public function detectSessions(Account $account, \DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $qb = $this->scheduledSetRepository->createQueryBuilder('ss')
            ->join('ss.telegramUserId', 'tu')
            ->andWhere('tu.account = :account')->setParameter('account', $account)
            ->andWhere('ss.scheduled_at >= :from')->setParameter('from', $from)
            ->andWhere('ss.scheduled_at < :until')->setParameter('until', $until)
            ->orderBy('ss.pavilion', 'ASC')
            ->addOrderBy('ss.scheduled_at', 'ASC');

        $sets = $qb->getQuery()->getResult();

        $sessions = [];
        $current = null;
        foreach ($sets as $set) {
            /** @var ScheduledSet $set */
            $startAt = $set->getScheduledDateTime();
            $endAt = (clone $startAt)->modify('+1 hour');

            if (
                $current !== null
                && $current['pavilion'] === $set->getPavilion()
                && $current['end']->getTimestamp() === $startAt->getTimestamp()
            ) {
                $current['end'] = $endAt;
                $current['sets'][] = $set;
                continue;
            }

            if ($current !== null) {
                $sessions[] = $current;
            }
            $current = [
                'pavilion' => $set->getPavilion(),
                'start' => $startAt,
                'end' => $endAt,
                'sets' => [$set],
            ];
        }
        if ($current !== null) {
            $sessions[] = $current;
        }

        return $sessions;
    }

    /**
     * Sessions whose `end` falls within the lookback window AND has already passed.
     * Used by the reminder cron to materialize pending obligations.
     *
     * @return array<int, array{account:Account, pavilion:int, start:\DateTime, end:\DateTime}>
     */
    public function findSessionsNeedingPhotos(\DateTime $now): array
    {
        $lookbackFrom = (clone $now)->modify('-' . self::LOOKBACK_HOURS . ' hours');
        $cutoff = $this->obligationStartAt();
        $from = $lookbackFrom > $cutoff ? $lookbackFrom : $cutoff;

        $rows = $this->scheduledSetRepository->createQueryBuilder('ss')
            ->select('DISTINCT IDENTITY(tu.account) AS account_id, ss.pavilion')
            ->join('ss.telegramUserId', 'tu')
            ->andWhere('ss.scheduled_at >= :from')->setParameter('from', $from)
            ->andWhere('ss.scheduled_at < :now')->setParameter('now', $now)
            ->andWhere('tu.account IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $accountIds = array_unique(array_map(static fn(array $r) => (int)$r['account_id'], $rows));
        if (!$accountIds) {
            return [];
        }

        $accounts = $this->em->getRepository(Account::class)->findBy(['id' => $accountIds]);

        $candidates = [];
        foreach ($accounts as $account) {
            $sessions = $this->detectSessions($account, $from, $now);
            foreach ($sessions as $session) {
                if ($session['end'] > $now) {
                    continue;
                }
                $candidates[] = [
                    'account' => $account,
                    'pavilion' => $session['pavilion'],
                    'start' => $session['start'],
                    'end' => $session['end'],
                ];
            }
        }

        return $candidates;
    }

    /**
     * @return array{request:PhotoUploadRequest, created:bool, preResolvedByPhoto:bool}
     */
    public function ensureRequest(Account $account, int $pavilion, \DateTime $start, \DateTime $end): array
    {
        $existing = $this->requestRepository->findForSession($account, $pavilion, $start, $end);
        if ($existing) {
            return ['request' => $existing, 'created' => false, 'preResolvedByPhoto' => false];
        }

        $photo = $this->photoRepository->findForSession($account, $pavilion, $start, $end);

        $req = new PhotoUploadRequest();
        $req->setAccount($account);
        $req->setPavilion($pavilion);
        $req->setSessionStartAt($start);
        $req->setSessionEndAt($end);
        if ($photo !== null) {
            $req->setResolvedAt(new \DateTime());
        }

        $this->em->persist($req);
        $this->em->flush();

        return ['request' => $req, 'created' => true, 'preResolvedByPhoto' => $photo !== null];
    }

    /**
     * Decide if a reminder is currently due (and which number) for an open request,
     * honoring the night-time deferral window.
     *
     * @return int|null The 1-based reminder number to send, or null if nothing due.
     */
    public function dueReminderNumber(PhotoUploadRequest $req, \DateTime $now): ?int
    {
        if (!$req->isOpen()) {
            return null;
        }

        $sent = $req->getRemindersSent();
        if ($sent >= count(self::REMINDER_OFFSETS_MIN)) {
            return null;
        }

        $offset = self::REMINDER_OFFSETS_MIN[$sent];
        $dueAt = $this->sessionEndKyiv($req)->modify('+' . $offset . ' minutes');
        $dueAt = $this->deferIfNight($dueAt);

        if ($now < $dueAt) {
            return null;
        }

        return $sent + 1;
    }

    /**
     * Re-anchor a session_end_at read from PG to Kyiv. Doctrine's default DateTimeType
     * loses the originating timezone on round-trip through `timestamp without time zone`,
     * so the wall-clock 10:00 (which was originally 10:00 Kyiv) comes back as 10:00 UTC.
     * Reinterpreting the same wall-clock in Kyiv restores the correct instant.
     */
    private function sessionEndKyiv(PhotoUploadRequest $req): \DateTime
    {
        return new \DateTime(
            $req->getSessionEndAt()->format('Y-m-d H:i:s'),
            new \DateTimeZone('Europe/Kyiv'),
        );
    }

    public function markReminderSent(PhotoUploadRequest $req, \DateTime $now): void
    {
        $req->setRemindersSent($req->getRemindersSent() + 1);
        $req->setLastReminderAt($now);
        $this->em->flush();
    }

    public function shouldBlock(PhotoUploadRequest $req, \DateTime $now): bool
    {
        if (!$req->isOpen() || $req->getBlockedAt() !== null) {
            return false;
        }
        if ($req->getRemindersSent() < count(self::REMINDER_OFFSETS_MIN)) {
            return false;
        }

        $blockAt = $this->sessionEndKyiv($req)->modify('+' . self::BLOCK_AFTER_MIN . ' minutes');
        $blockAt = $this->deferIfNight($blockAt);

        return $now >= $blockAt;
    }

    public function markBlocked(PhotoUploadRequest $req, \DateTime $now): void
    {
        $req->setBlockedAt($now);
        $this->em->flush();
    }

    public function resolveRequest(PhotoUploadRequest $req, \DateTime $now): void
    {
        $req->setResolvedAt($now);
        $this->em->flush();
    }

    /**
     * Save a photo file fetched from Telegram against an open request.
     */
    public function attachPhoto(
        PhotoUploadRequest $req,
        TelegramUser $uploader,
        string $sourcePath,
        string $telegramFileId,
        string $extension = 'jpg',
    ): PavilionPhoto {
        $start = $req->getSessionStartAt();
        $subdir = $start->format('Y/m');
        $targetDir = $this->uploadDir . '/' . $subdir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Failed to create upload directory: ' . $targetDir);
        }

        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'jpg');
        $filename = sprintf(
            '%s-acc%d-alt%d-%s.%s',
            $start->format('Ymd-Hi'),
            $req->getAccount()->getId(),
            $req->getPavilion(),
            bin2hex(random_bytes(4)),
            $ext,
        );
        $target = $targetDir . '/' . $filename;

        if (!@rename($sourcePath, $target)) {
            if (!@copy($sourcePath, $target)) {
                throw new \RuntimeException('Failed to store uploaded photo at ' . $target);
            }
            @unlink($sourcePath);
        }

        $photo = new PavilionPhoto();
        $photo->setAccount($req->getAccount());
        $photo->setUploader($uploader);
        $photo->setPavilion($req->getPavilion());
        $photo->setSessionStartAt($start);
        $photo->setSessionEndAt($req->getSessionEndAt());
        $photo->setFilePath($this->publicPathPrefix . '/' . $subdir . '/' . $filename);
        $photo->setTelegramFileId($telegramFileId);

        $this->em->persist($photo);
        $req->setResolvedAt(new \DateTime());

        // A single session can have spawned multiple overlapping requests (the materializer
        // bug fixed elsewhere). One upload should clear every sibling whose window is
        // covered by this photo's window — same pavilion, photo's start <= sibling's start
        // and photo's end >= sibling's end. Without this, the user would have to upload
        // the same photo two or three times to fully unblock the account.
        foreach ($this->requestRepository->findOpenForAccount($req->getAccount()) as $sibling) {
            if ($sibling->getId() === $req->getId()) {
                continue;
            }
            if ($sibling->getPavilion() !== $req->getPavilion()) {
                continue;
            }
            if ($photo->getSessionStartAt() > $sibling->getSessionStartAt()) {
                continue;
            }
            if ($photo->getSessionEndAt() < $sibling->getSessionEndAt()) {
                continue;
            }
            $sibling->setResolvedAt(new \DateTime());
        }

        $this->em->flush();

        $this->maybeAutoUnblockAfterUpload($req);

        return $photo;
    }

    /**
     * If a photo just cleared a request that had set blocked_at, restore the account's
     * is_active flag — but only when (a) the account is currently inactive, (b) debt is
     * within threshold, and (c) no OTHER open blocked request remains for this account.
     * This handles the "user uploaded after auto-block" case so admins don't have to
     * manually flip is_active in /admin/users.
     */
    private function maybeAutoUnblockAfterUpload(PhotoUploadRequest $req): void
    {
        if ($req->getBlockedAt() === null) {
            return;
        }

        $account = $req->getAccount();
        if ($account->isActive() === true) {
            return;
        }
        if ($this->debtPolicy->isAccountBlocked($account)) {
            return;
        }

        $remainingBlocked = $this->requestRepository->createQueryBuilder('r')
            ->andWhere('r.account = :a')->setParameter('a', $account)
            ->andWhere('r.resolved_at IS NULL')
            ->andWhere('r.blocked_at IS NOT NULL')
            ->andWhere('r.id != :id')->setParameter('id', $req->getId())
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        if ($remainingBlocked !== null) {
            return;
        }

        $account->setIsActive(true);
        $this->em->flush();
    }

    /**
     * Called when an admin un-blocks an account: forgive any currently-blocking open requests.
     * @return int number of requests forgiven
     */
    public function forgiveBlockingRequests(Account $account, \DateTime $now): int
    {
        $count = 0;
        foreach ($this->requestRepository->findOpenForAccount($account) as $req) {
            if ($req->getBlockedAt() !== null) {
                $this->resolveRequest($req, $now);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Cutoff before which sessions don't need a photo (grandfathered legacy bookings).
     */
    public function obligationStartAt(): \DateTime
    {
        return new \DateTime(self::OBLIGATION_START_AT, new \DateTimeZone('Europe/Kyiv'));
    }

    public function isLegacySession(\DateTimeInterface $sessionEnd): bool
    {
        return $sessionEnd < $this->obligationStartAt();
    }

    private function deferIfNight(\DateTime $dt): \DateTime
    {
        $hour = (int)$dt->format('H');
        if ($hour < self::NIGHT_END_HOUR) {
            $deferred = (clone $dt)->setTime(self::NIGHT_END_HOUR, 0, 0);
            return $deferred;
        }
        if ($hour >= self::NIGHT_START_HOUR) {
            $deferred = (clone $dt)->modify('+1 day')->setTime(self::NIGHT_END_HOUR, 0, 0);
            return $deferred;
        }
        return $dt;
    }
}
