<?php

namespace App\Repository;

use App\Entity\LinkClick;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LinkClick>
 */
class LinkClickRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkClick::class);
    }

    /**
     * One row per post that has ever been opened, most-clicked first.
     *
     * Both numbers are printed on the page and they answer different questions: `clicks`
     * is how much attention the post got, `people` is how many households it reached. A
     * neighbour who opens the same electrician three times is interested, not three people.
     *
     * @return array<int, array{kind: string, target_id: int, clicks: int, people: int, last_at: string}>
     */
    public function summary(int $limit = 200): array
    {
        return $this->createQueryBuilder('c')
            ->select(
                'c.kind AS kind',
                'c.target_id AS target_id',
                'COUNT(c.id) AS clicks',
                'COUNT(DISTINCT c.user) AS people',
                'MAX(c.created_at) AS last_at',
            )
            ->groupBy('c.kind')
            ->addGroupBy('c.target_id')
            ->orderBy('clicks', 'DESC')
            ->addOrderBy('last_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * The raw list — who, what and when.
     *
     * @return LinkClick[]
     */
    public function recent(int $limit = 300): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->leftJoin('u.account', 'a')->addSelect('a')
            ->orderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Clicks on one post, for the card that shows its author how it is doing. */
    public function countFor(string $kind, ?int $targetId): int
    {
        if ($targetId === null) {
            return 0;
        }

        return (int)$this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.kind = :kind')->setParameter('kind', $kind)
            ->andWhere('c.target_id = :id')->setParameter('id', $targetId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
