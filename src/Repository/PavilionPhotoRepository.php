<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\PavilionPhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PavilionPhoto>
 */
class PavilionPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PavilionPhoto::class);
    }

    public function findForSession(Account $account, int $pavilion, \DateTimeInterface $sessionStartAt): ?PavilionPhoto
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.account = :account')->setParameter('account', $account)
            ->andWhere('p.pavilion = :pavilion')->setParameter('pavilion', $pavilion)
            ->andWhere('p.session_start_at = :start')->setParameter('start', $sessionStartAt)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return PavilionPhoto[]
     */
    public function findOlderThan(\DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.created_at < :cutoff')->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Lookup table for booking-history rendering: account_id+pavilion+session_start => PavilionPhoto.
     * @return array<string, PavilionPhoto>
     */
    public function indexBySession(\DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.session_start_at >= :from')->setParameter('from', $from)
            ->andWhere('p.session_start_at < :until')->setParameter('until', $until)
            ->getQuery()
            ->getResult();

        $index = [];
        /** @var PavilionPhoto $p */
        foreach ($rows as $p) {
            $key = sprintf('%d:%d:%s', $p->getAccount()->getId(), $p->getPavilion(), $p->getSessionStartAt()->format('Y-m-d H:i:s'));
            $index[$key] = $p;
        }
        return $index;
    }
}
