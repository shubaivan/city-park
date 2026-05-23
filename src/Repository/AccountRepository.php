<?php

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Every Account that shares an owner-group with the given account, including itself.
     * Ungrouped accounts (owner_group_id IS NULL) return just themselves.
     *
     * @return Account[]
     */
    public function findGroupSiblings(Account $account): array
    {
        if ($account->getOwnerGroupId() === null) {
            return [$account];
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.owner_group_id = :gid')
            ->setParameter('gid', $account->getOwnerGroupId())
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
