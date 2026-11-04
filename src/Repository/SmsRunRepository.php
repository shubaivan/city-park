<?php

namespace App\Repository;

use App\Entity\SmsLog;
use App\Entity\SmsRun;
use App\Service\SmsSender;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SmsRun>
 */
class SmsRunRepository extends ServiceEntityRepository
{
    /** Queued, and no new row for this long: nothing is consuming the queue. */
    private const STALLED_AFTER_SECONDS = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SmsRun::class);
    }

    /**
     * A run that may still send. Yesterday's does not count, since its jobs can only stop by now
     * (DebtSmsDelivery), so a worker that was down cannot lock the button for good.
     */
    public function findActive(\DateTimeImmutable $now): ?SmsRun
    {
        $run = $this->findOneBy(['status' => SmsRun::STATUS_RUNNING], ['id' => 'DESC']);

        return $run instanceof SmsRun && $run->isOfTheDay($now) ? $run : null;
    }

    /**
     * What the panel shows: today's latest run. An older one still marked running is nobody's
     * business on the page: its jobs can only stop, and the page must not reload for it.
     *
     * @return array{run: SmsRun, total: int, sent: int, failed: int, unknown: int, sending: int, queued: int, stalled: bool}|null
     */
    public function latestStatus(\DateTimeImmutable $now): ?array
    {
        $run = $this->findOneBy([], ['id' => 'DESC']);
        if (!$run instanceof SmsRun || !$run->isOfTheDay($now)) {
            return null;
        }

        $counts = $this->getEntityManager()->createQuery(
            'SELECT s.status AS status, CASE WHEN s.error LIKE :unknown THEN 1 ELSE 0 END AS isUnknown,
                    COUNT(s.id) AS rows, MAX(s.created_at) AS last
             FROM ' . SmsLog::class . ' s WHERE s.run = :run GROUP BY s.status, isUnknown'
        )->setParameter('run', $run)->setParameter('unknown', SmsSender::OUTCOME_UNKNOWN . '%')->getArrayResult();

        $status = ['run' => $run, 'total' => $run->getTotal(), 'sent' => 0, 'failed' => 0, 'unknown' => 0, 'sending' => 0, 'queued' => 0, 'stalled' => false];
        $last = $run->getCreatedAt()->format('Y-m-d H:i:s');
        $written = 0;

        foreach ($counts as $row) {
            $n = (int)$row['rows'];
            $written += $n;
            $last = max($last, (string)$row['last']);

            match (true) {
                $row['status'] === SmsLog::STATUS_SENT => $status['sent'] += $n,
                $row['status'] === SmsLog::STATUS_SENDING => $status['sending'] += $n,
                (int)$row['isUnknown'] === 1 => $status['unknown'] += $n,
                default => $status['failed'] += $n,
            };
        }

        $status['queued'] = max(0, $run->getTotal() - $written);

        $kyiv = new \DateTimeZone('Europe/Kyiv');
        $idle = $now->getTimestamp() - (new \DateTimeImmutable($last, $kyiv))->getTimestamp();
        $status['stalled'] = $run->isRunning() && $status['queued'] > 0 && $idle > self::STALLED_AFTER_SECONDS;

        return $status;
    }

    /** Runs from earlier days still marked running. Their jobs, if any are left, then write the reason. */
    public function closeStale(\DateTimeImmutable $now): int
    {
        return (int)$this->getEntityManager()->createQuery(
            'UPDATE ' . SmsRun::class . ' r SET r.status = :stopped, r.stop_reason = :reason WHERE r.status = :running AND r.created_at < :today'
        )
            ->setParameter('stopped', SmsRun::STATUS_STOPPED)
            ->setParameter('reason', SmsRun::REASON_STALE)
            ->setParameter('running', SmsRun::STATUS_RUNNING)
            ->setParameter('today', $now->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d') . ' 00:00:00')
            ->execute();
    }

    /** Rows a run has so far, in any state. */
    public function writtenRows(SmsRun $run): int
    {
        return (int)$this->getEntityManager()->createQuery('SELECT COUNT(s.id) FROM ' . SmsLog::class . ' s WHERE s.run = :run')
            ->setParameter('run', $run)
            ->getSingleScalarResult();
    }
}
