<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\ExpectedResident;
use App\Service\PhoneKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExpectedResident>
 */
class ExpectedResidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpectedResident::class);
    }

    /**
     * The one still waiting for this number, if there is one.
     *
     * An exact match on the stored key, never a LIKE: `PhoneKey::of()` has already reduced
     * both sides to the same nine digits, and a key that came back empty (anything too
     * short to be a phone) must match nothing rather than the first row in the table.
     */
    public function findUnclaimedByPhone(?string $phone): ?ExpectedResident
    {
        $key = PhoneKey::of($phone);
        if ($key === '') {
            return null;
        }

        return $this->createQueryBuilder('e')
            ->andWhere('e.phone_key = :k')->setParameter('k', $key)
            ->andWhere('e.claimed_at IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Whoever already holds this number, claimed or not — what the panel needs to refuse a
     * duplicate by name («цей номер уже очікується на буд. 19, кв. 50») instead of throwing
     * a unique-constraint violation at the accountant.
     */
    public function findByPhone(?string $phone): ?ExpectedResident
    {
        $key = PhoneKey::of($phone);
        if ($key === '') {
            return null;
        }

        return $this->findOneBy(['phone_key' => $key]);
    }

    /**
     * Everything written down for one object, newest first.
     *
     * Claimed rows stay in the list: the card has to be able to say «✅ прийшов», or a
     * registration that worked is indistinguishable from one nobody ever typed.
     *
     * @return ExpectedResident[]
     */
    public function forAccount(Account $account): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.account = :a')->setParameter('a', $account)
            ->orderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
