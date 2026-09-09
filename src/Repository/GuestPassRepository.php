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
}
