<?php

namespace App\Repository;

use App\Entity\SmsLog;
use App\Service\SmsSender;
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
     * and reads as harassment. A send that ended without an answer counts as sent, and so does
     * one still `sending`: it may well have gone (see SmsSender::OUTCOME_UNKNOWN).
     */
    public function alreadySentToday(string $phoneKey, string $purpose): bool
    {
        $start = new \DateTime('today', new \DateTimeZone('Europe/Kyiv'));

        return (int)$this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.phone_key = :k')->setParameter('k', $phoneKey)
            ->andWhere('s.purpose = :p')->setParameter('p', $purpose)
            ->andWhere('s.status = :sent OR s.status = :sending OR (s.status = :failed AND s.error LIKE :unknown)')
            ->setParameter('sent', SmsLog::STATUS_SENT)
            ->setParameter('sending', SmsLog::STATUS_SENDING)
            ->setParameter('failed', SmsLog::STATUS_FAILED)
            ->setParameter('unknown', SmsSender::OUTCOME_UNKNOWN . '%')
            ->andWhere('s.created_at >= :start')->setParameter('start', $start)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Rows from this Kyiv day that may have cost money, whoever sent them: sent, still `sending`,
     * or an outcome nobody learned. What the daily ceiling counts (SmsSender).
     */
    public function costlyToday(): int
    {
        return (int)$this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.status = :sent OR s.status = :sending OR (s.status = :failed AND s.error LIKE :unknown)')
            ->setParameter('sent', SmsLog::STATUS_SENT)
            ->setParameter('sending', SmsLog::STATUS_SENDING)
            ->setParameter('failed', SmsLog::STATUS_FAILED)
            ->setParameter('unknown', SmsSender::OUTCOME_UNKNOWN . '%')
            ->andWhere('s.created_at >= :start')->setParameter('start', new \DateTime('today', new \DateTimeZone('Europe/Kyiv')))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Totals for the panel's header. Sent is what TurboSMS confirmed. Unknown, a row still
     * `sending` or an outcome nobody learned, may have been billed: counted apart, not hidden.
     *
     * @return array{sent: int, failed: int, parts: int, unknown: int, unknownParts: int}
     */
    public function summarySince(\DateTimeInterface $since): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, CASE WHEN s.error LIKE :unknown THEN 1 ELSE 0 END AS isUnknown, COUNT(s.id) AS messages, SUM(s.parts) AS parts')
            ->andWhere('s.created_at >= :since')->setParameter('since', $since)
            ->setParameter('unknown', SmsSender::OUTCOME_UNKNOWN . '%')
            ->groupBy('s.status, isUnknown')
            ->getQuery()
            ->getArrayResult();

        $out = ['sent' => 0, 'failed' => 0, 'parts' => 0, 'unknown' => 0, 'unknownParts' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === SmsLog::STATUS_SENT) {
                $out['sent'] += (int)$row['messages'];
                $out['parts'] += (int)$row['parts'];
            } elseif ($row['status'] === SmsLog::STATUS_SENDING || ($row['status'] === SmsLog::STATUS_FAILED && (int)$row['isUnknown'] === 1)) {
                $out['unknown'] += (int)$row['messages'];
                $out['unknownParts'] += (int)$row['parts'];
            } elseif ($row['status'] === SmsLog::STATUS_FAILED) {
                $out['failed'] += (int)$row['messages'];
            }
        }

        return $out;
    }
}
