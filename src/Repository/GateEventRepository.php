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
}
