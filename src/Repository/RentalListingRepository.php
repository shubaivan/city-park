<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\RentalListing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RentalListing>
 */
class RentalListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentalListing::class);
    }

    /**
     * Everything currently on offer, newest first — the list residents see in the bot.
     *
     * Filters on expires_at as well as status so a listing disappears the moment it is
     * stale even if the daily rental:expire cron has not run yet.
     *
     * @return RentalListing[]
     */
    public function findActive(\DateTime $now, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :active')
            ->andWhere('l.expires_at > :now')
            ->setParameter('active', RentalListing::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The account's own live listing, if any. One active listing per account is the
     * invariant the publish flow enforces; findOneBy-style access keeps it readable.
     */
    public function findActiveForAccount(Account $account, \DateTime $now): ?RentalListing
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.account = :acc')
            ->andWhere('l.status = :active')
            ->andWhere('l.expires_at > :now')
            ->setParameter('acc', $account)
            ->setParameter('active', RentalListing::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Active listings whose expiry falls inside (now, soon] and that haven't been asked
     * "ще актуально?" yet.
     *
     * @return RentalListing[]
     */
    public function findDueRenewPrompt(\DateTime $now, \DateTime $soon): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :active')
            ->andWhere('l.renew_prompt_sent_at IS NULL')
            ->andWhere('l.expires_at > :now')
            ->andWhere('l.expires_at <= :soon')
            ->setParameter('active', RentalListing::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->setParameter('soon', $soon)
            ->getQuery()
            ->getResult();
    }

    /**
     * Active listings past their expiry — ready to be closed by the cron.
     *
     * @return RentalListing[]
     */
    public function findExpired(\DateTime $now): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :active')
            ->andWhere('l.expires_at <= :now')
            ->setParameter('active', RentalListing::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Most recent listings of any status, for the admin table.
     *
     * @return RentalListing[]
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
