<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountStatusLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountStatusLog>
 */
class AccountStatusLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountStatusLog::class);
    }

    /**
     * @return AccountStatusLog[]
     */
    public function findRecentForAccount(Account $account, int $limit = 5): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.account = :a')->setParameter('a', $account)
            ->orderBy('l.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
