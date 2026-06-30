<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\BlockVoteBallot;
use App\Entity\BlockVoteCampaign;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlockVoteBallot>
 */
class BlockVoteBallotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlockVoteBallot::class);
    }

    public function findOneByCampaignAndVoter(BlockVoteCampaign $campaign, Account $voter): ?BlockVoteBallot
    {
        return $this->findOneBy(['campaign' => $campaign, 'voterAccount' => $voter]);
    }

    /**
     * Tally a campaign in one query: [yes => int, no => int].
     *
     * @return array{yes:int, no:int}
     */
    public function tally(BlockVoteCampaign $campaign): array
    {
        // Count total and YES with explicit boolean predicates rather than grouping on the
        // boolean column — Postgres/Doctrine boolean hydration of a grouped key is driver-
        // dependent ('t'/'f' vs bool), which could silently invert the tally.
        $total = (int)$this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.campaign = :c')
            ->setParameter('c', $campaign)
            ->getQuery()
            ->getSingleScalarResult();

        $yes = (int)$this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.campaign = :c')
            ->andWhere('b.value = :yes')
            ->setParameter('c', $campaign)
            ->setParameter('yes', true)
            ->getQuery()
            ->getSingleScalarResult();

        return ['yes' => $yes, 'no' => $total - $yes];
    }

    /**
     * Account ids that have already cast a ballot in this campaign.
     *
     * @return int[]
     */
    public function votedAccountIds(BlockVoteCampaign $campaign): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.voterAccount) AS aid')
            ->andWhere('b.campaign = :c')
            ->setParameter('c', $campaign)
            ->getQuery()
            ->getResult();

        return array_map(static fn($r) => (int)$r['aid'], $rows);
    }
}
