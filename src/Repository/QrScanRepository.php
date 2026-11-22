<?php

namespace App\Repository;

use App\Entity\QrScan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QrScan>
 */
class QrScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QrScan::class);
    }

    /**
     * The log, newest first.
     *
     * @return QrScan[]
     */
    public function latest(int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The scans of one guest pass, newest first — «коли прийшли будівельники».
     *
     * @return QrScan[]
     */
    public function forPass(int $passId, int $limit = 5): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.guest_pass_id = :p')->setParameter('p', $passId)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Has this pass already been through the gate today?
     *
     * The flat is told when its crew arrives, and «arrives» is the *first* valid scan of
     * the day: a guard who checks the same people twice at the door has not made them
     * arrive twice, and a second message would teach the resident to mute the first.
     */
    public function passWasScannedToday(int $passId, \DateTimeInterface $day): bool
    {
        return (bool)$this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.guest_pass_id = :p')->setParameter('p', $passId)
            ->andWhere('s.result = :ok')->setParameter('ok', \App\Entity\QrScan::RESULT_OK)
            ->andWhere('s.created_at >= :from')->setParameter('from', new \DateTime($day->format('Y-m-d') . ' 00:00:00'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** How many scans happened, so the page can say what it is not showing. */
    public function total(): int
    {
        return (int)$this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The scans of [from, to), newest first — one week of the guard's feed (20.11.2026).
     * Rides on qs_created_idx; the cap only guards against a runaway week.
     *
     * @return \App\Entity\QrScan[]
     */
    public function between(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 3000, ?int $passId = null, ?int $personId = null): array
    {
        return $this->filtered($passId, $personId)
            ->andWhere('s.created_at >= :from')->setParameter('from', $from)
            ->andWhere('s.created_at < :to')->setParameter('to', $to)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * What arrived after the scan the page last saw — the feed's 10-second poll.
     *
     * @return \App\Entity\QrScan[]
     */
    public function newerThan(int $afterId, \DateTimeInterface $from, int $limit = 200, ?int $passId = null, ?int $personId = null): array
    {
        return $this->filtered($passId, $personId)
            ->andWhere('s.id > :id')->setParameter('id', $afterId)
            ->andWhere('s.created_at >= :from')->setParameter('from', $from)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The feed's query: the scanned person fetched with the row (one query for a week, not
     * one per line), narrowed to one pass (`?pass=`) or one person's personal code
     * (`?person=`, 22.11.2026) when asked.
     */
    private function filtered(?int $passId, ?int $personId): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.subjectUser', 'su')->addSelect('su');

        if ($personId !== null) {
            $qb->andWhere('su.id = :person')->setParameter('person', $personId);
        }

        return $this->onlyPass($qb, $passId);
    }

    /** `?pass=<id>` on /admin/scans: one pass's lines only, revoked and expired passes too. */
    private function onlyPass(\Doctrine\ORM\QueryBuilder $qb, ?int $passId): \Doctrine\ORM\QueryBuilder
    {
        if ($passId !== null) {
            $alias = $qb->getRootAliases()[0];
            $qb->andWhere($alias . '.guest_pass_id = :pass')->setParameter('pass', $passId);
        }

        return $qb;
    }
}
