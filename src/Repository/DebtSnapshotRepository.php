<?php

namespace App\Repository;

use App\Entity\DebtSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DebtSnapshot>
 */
class DebtSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DebtSnapshot::class);
    }

    public function latest(): ?DebtSnapshot
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.taken_at', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The snapshot before the given one — the baseline the announcement compares against.
     */
    public function previousTo(DebtSnapshot $snapshot): ?DebtSnapshot
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.id < :id')
            ->setParameter('id', $snapshot->getId())
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function announcedSince(\DateTimeImmutable $since): bool
    {
        $count = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.announced_at IS NOT NULL')
            ->andWhere('s.announced_at >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int)$count > 0;
    }
}
