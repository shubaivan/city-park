<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\GuestPass;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestPass>
 */
class GuestPassRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestPass::class);
    }

    /**
     * The flat's live passes, newest first.
     *
     * The whole household's, not the issuer's: a pass belongs to the flat, and the person
     * who has to switch it on in the morning is whoever is awake.
     *
     * @return GuestPass[]
     */
    public function liveFor(Account $account): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.account = :a')->setParameter('a', $account)
            ->andWhere('p.revoked_at IS NULL')
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The house's live passes for /admin/scans, newest first, with their issuers in the
     * same query («Видав» is on every row).
     *
     * @return GuestPass[]
     */
    public function liveWithIssuers(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.issuedBy', 'u')->addSelect('u')
            ->andWhere('p.revoked_at IS NULL')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Passes by id with their issuer in the same query — revoked and expired ones too: the
     * scan feed names the issuer of every pass it shows (GuardFeed::withContacts()).
     *
     * @param int[] $ids
     *
     * @return GuestPass[]
     */
    public function withIssuers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->leftJoin('p.issuedBy', 'u')->addSelect('u')
            ->andWhere('p.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
