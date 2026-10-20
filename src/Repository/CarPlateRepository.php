<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\CarPlate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CarPlate>
 */
class CarPlateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CarPlate::class);
    }

    /** @return CarPlate[] */
    public function forAccount(Account $account): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.account = :a')->setParameter('a', $account)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByKey(string $key): ?CarPlate
    {
        return $key === '' ? null : $this->findOneBy(['plate' => $key]);
    }

    /**
     * Every number on record, by building and flat — the panel's list and its export.
     *
     * @return CarPlate[]
     */
    public function allWithAccounts(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a', 'u')
            ->join('c.account', 'a')
            ->leftJoin('c.addedBy', 'u')
            ->orderBy('a.account_number', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
