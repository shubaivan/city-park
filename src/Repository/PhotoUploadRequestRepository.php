<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\PhotoUploadRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhotoUploadRequest>
 */
class PhotoUploadRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoUploadRequest::class);
    }

    /**
     * Find an existing request whose session window overlaps the candidate window.
     *
     * The materializer's lookback can re-detect the same physical booking as a shrinking
     * suffix as the earliest hours fall out of the 26h window (e.g. 18-21 → 19-21 → 20-21).
     * Matching on overlap (rather than exact start) keeps one request per physical session.
     */
    public function findForSession(Account $account, int $pavilion, \DateTimeInterface $sessionStartAt, \DateTimeInterface $sessionEndAt): ?PhotoUploadRequest
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.account = :account')->setParameter('account', $account)
            ->andWhere('r.pavilion = :pavilion')->setParameter('pavilion', $pavilion)
            ->andWhere('r.session_start_at < :end')->setParameter('end', $sessionEndAt)
            ->andWhere('r.session_end_at > :start')->setParameter('start', $sessionStartAt)
            ->orderBy('r.session_start_at', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return PhotoUploadRequest[] Open (unresolved) requests, optionally filtered to one account.
     */
    public function findOpen(?Account $account = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.resolved_at IS NULL')
            ->orderBy('r.session_end_at', 'ASC');

        if ($account) {
            $qb->andWhere('r.account = :account')->setParameter('account', $account);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return PhotoUploadRequest[] Open requests for any user belonging to an account.
     */
    public function findOpenForAccount(Account $account): array
    {
        return $this->findOpen($account);
    }
}
