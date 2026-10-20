<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Entity\TelegramUser;
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

    /**
     * What one person wrote down — on their flat, or on one they were linked to before.
     *
     * @return CarPlate[]
     */
    public function addedBy(TelegramUser $user): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.account', 'a')
            ->andWhere('c.addedBy = :u')->setParameter('u', $user)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The resident card's list: the flat's cars, whoever added them, plus anything this
     * person added on another object. «Які номери він вносив» and «які авто в цій квартирі»
     * are the two questions asked of that card, and they are usually one list.
     *
     * @return CarPlate[]
     */
    public function forResident(TelegramUser $user): array
    {
        $byId = [];

        foreach ($user->getAccount() ? $this->forAccount($user->getAccount()) : [] as $plate) {
            $byId[$plate->getId()] = $plate;
        }

        foreach ($this->addedBy($user) as $plate) {
            $byId[$plate->getId()] = $plate;
        }

        ksort($byId);

        return array_values($byId);
    }

    /** How many objects have at least one number on the list. */
    public function householdCount(): int
    {
        return (int)$this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT IDENTITY(c.account))')
            ->getQuery()
            ->getSingleScalarResult();
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
