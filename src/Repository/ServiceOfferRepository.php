<?php

namespace App\Repository;

use App\Entity\ServiceOffer;
use App\Entity\TelegramUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceOffer>
 */
class ServiceOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceOffer::class);
    }

    /**
     * Everything currently on offer, newest first.
     *
     * Filters on expires_at as well as status so a stale offer disappears the moment it
     * is stale, even if the daily service:expire cron has not run yet.
     *
     * @return ServiceOffer[]
     */
    public function findActive(\DateTime $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status = :active')
            ->andWhere('o.expires_at > :now')
            ->setParameter('active', ServiceOffer::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->orderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything this person currently offers.
     *
     * Keyed on the author and not the account: two people on one особовий рахунок may each
     * offer something different — see the ServiceOffer class comment. There is no cap; a
     * person really can be an electrician *and* fit kitchens, and «Електрик, ремонт під
     * ключ» crammed into one 60-character button serves neither.
     *
     * @return ServiceOffer[]
     */
    public function findActiveForAuthor(TelegramUser $author, \DateTime $now): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.author = :author')
            ->andWhere('o.status = :active')
            ->andWhere('o.expires_at > :now')
            ->setParameter('author', $author)
            ->setParameter('active', ServiceOffer::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active offers whose expiry falls inside (now, soon] and that haven't been asked
     * "ще актуально?" yet.
     *
     * @return ServiceOffer[]
     */
    public function findDueRenewPrompt(\DateTime $now, \DateTime $soon): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status = :active')
            ->andWhere('o.renew_prompt_sent_at IS NULL')
            ->andWhere('o.expires_at > :now')
            ->andWhere('o.expires_at <= :soon')
            ->setParameter('active', ServiceOffer::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->setParameter('soon', $soon)
            ->getQuery()
            ->getResult();
    }

    /**
     * Active offers past their expiry — ready to be closed by the cron.
     *
     * @return ServiceOffer[]
     */
    public function findExpired(\DateTime $now): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status = :active')
            ->andWhere('o.expires_at <= :now')
            ->setParameter('active', ServiceOffer::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    public function findOneByToken(string $token): ?ServiceOffer
    {
        return $this->findOneBy(['photo_token' => $token]);
    }

    /**
     * Most recent offers of any status, for the admin table.
     *
     * @return ServiceOffer[]
     */
    public function findRecent(int $limit = 200): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** How many offers are live right now — the count on the menu button. */
    public function countActive(\DateTime $now): int
    {
        return (int)$this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.status = :active')
            ->andWhere('o.expires_at > :now')
            ->setParameter('active', ServiceOffer::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
