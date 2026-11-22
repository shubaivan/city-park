<?php

namespace App\Repository;

use App\Entity\GateEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GateEvent>
 */
class GateEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GateEvent::class);
    }

    /** The event already recorded under this scan, if any — what makes the button idempotent. */
    public function forScan(int $scanId): ?GateEvent
    {
        return $this->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.qrScan) = :s')->setParameter('s', $scanId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * One Kyiv day of entries and exits, newest first.
     *
     * @return GateEvent[]
     */
    /**
     * Has the guard logged this pass going *out* earlier that day? Then a «🚗 В'їзд» is the
     * crew coming back, and the flat is told (GateLogService::issuerNotice).
     */
    public function passLeftEarlier(int $passId, \DateTimeInterface $day, int $beforeEventId = PHP_INT_MAX): bool
    {
        return (bool)$this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.guest_pass_id = :p')->setParameter('p', $passId)
            ->andWhere('e.direction = :out')->setParameter('out', GateEvent::DIRECTION_OUT)
            ->andWhere('e.id < :before')->setParameter('before', $beforeEventId)
            ->andWhere('e.created_at >= :from')->setParameter('from', new \DateTime($day->format('Y-m-d') . ' 00:00:00'))
            ->andWhere('e.created_at < :to')->setParameter('to', new \DateTime($day->format('Y-m-d') . ' 00:00:00 +1 day'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function ofDay(\DateTimeInterface $day, int $limit = 300): array
    {
        $from = new \DateTime($day->format('Y-m-d') . ' 00:00:00');

        return $this->createQueryBuilder('e')
            ->andWhere('e.created_at >= :from')->setParameter('from', $from)
            ->andWhere('e.created_at < :to')->setParameter('to', (clone $from)->modify('+1 day'))
            ->orderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Entries and exits of [from, to), newest first — one week of the guard's feed.
     *
     * @return GateEvent[]
     */
    public function between(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 3000, ?int $passId = null, ?int $personId = null): array
    {
        return $this->filtered($passId, $personId)
            ->andWhere('e.created_at >= :from')->setParameter('from', $from)
            ->andWhere('e.created_at < :to')->setParameter('to', $to)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * What was pressed after the event the page last saw — the feed's poll.
     *
     * @return GateEvent[]
     */
    public function newerThan(int $afterId, \DateTimeInterface $from, int $limit = 200, ?int $passId = null, ?int $personId = null): array
    {
        return $this->filtered($passId, $personId)
            ->andWhere('e.id > :id')->setParameter('id', $afterId)
            ->andWhere('e.created_at >= :from')->setParameter('from', $from)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The feed's query: the scan behind each press and its person fetched with it, and
     * narrowed to one person's personal code (`?person=`, 22.11.2026) when asked.
     */
    private function filtered(?int $passId, ?int $personId): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.qrScan', 'q')->addSelect('q')
            ->leftJoin('q.subjectUser', 'qu')->addSelect('qu');

        if ($personId !== null) {
            $qb->andWhere('qu.id = :person')->setParameter('person', $personId);
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
