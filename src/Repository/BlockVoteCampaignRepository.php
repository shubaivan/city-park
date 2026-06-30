<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\BlockVoteCampaign;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlockVoteCampaign>
 */
class BlockVoteCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlockVoteCampaign::class);
    }

    /**
     * All currently-open campaigns, soonest deadline first.
     *
     * @return BlockVoteCampaign[]
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :open')
            ->setParameter('open', BlockVoteCampaign::STATUS_OPEN)
            ->orderBy('c.deadline_at', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open campaigns whose deadline has passed — ready to be tallied/closed.
     *
     * @return BlockVoteCampaign[]
     */
    public function findExpiredOpen(\DateTime $now): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :open')
            ->andWhere('c.deadline_at <= :now')
            ->setParameter('open', BlockVoteCampaign::STATUS_OPEN)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Is there already an open campaign for this candidate? Prevents duplicates.
     */
    public function findOpenForCandidate(Account $candidate): ?BlockVoteCampaign
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.candidate = :acc')
            ->andWhere('c.status = :open')
            ->setParameter('acc', $candidate)
            ->setParameter('open', BlockVoteCampaign::STATUS_OPEN)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Most recent campaigns (any status) for the admin listing.
     *
     * @return BlockVoteCampaign[]
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
