<?php

namespace App\Repository;

use App\Entity\SmsLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SmsLog>
 */
class SmsLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SmsLog::class);
    }

    /**
     * The journal, newest first — what the panel shows.
     *
     * @return SmsLog[]
     */
    public function recent(int $limit = 200): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Has this number already been written to today for this purpose?
     *
     * The guard against the commonest and most expensive mistake there is here: a cron
     * that runs twice, or an import re-uploaded after a correction, sending the same
     * «у вас борг» to the same person again. Telegram forgives that; an SMS costs money
     * and reads as harassment.
     */
    public function alreadySentToday(string $phoneKey, string $purpose): bool
    {
        $start = new \DateTime('today', new \DateTimeZone('Europe/Kyiv'));

        return (int)$this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.phone_key = :k')->setParameter('k', $phoneKey)
            ->andWhere('s.purpose = :p')->setParameter('p', $purpose)
            ->andWhere('s.status = :sent')->setParameter('sent', SmsLog::STATUS_SENT)
            ->andWhere('s.created_at >= :start')->setParameter('start', $start)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Totals for the panel's header: how many went out and what they cost. */
    public function summarySince(\DateTimeInterface $since): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS messages, SUM(s.parts) AS parts')
            ->andWhere('s.created_at >= :since')->setParameter('since', $since)
            ->groupBy('s.status')
            ->getQuery()
            ->getArrayResult();

        $out = ['sent' => 0, 'failed' => 0, 'parts' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === SmsLog::STATUS_SENT) {
                $out['sent'] = (int)$row['messages'];
                $out['parts'] += (int)$row['parts'];
            } elseif ($row['status'] === SmsLog::STATUS_FAILED) {
                $out['failed'] = (int)$row['messages'];
            }
        }

        return $out;
    }
}
