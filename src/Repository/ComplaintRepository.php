<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Complaint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Complaint>
 */
class ComplaintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Complaint::class);
    }

    /**
     * The register as residents read it: everything still open first, newest first, then
     * the finished ones below. A resident scanning for "чи вже повідомили про ліфт" reads
     * the top of the list, so what is unresolved has to be there.
     *
     * @return Complaint[]
     */
    public function findForList(int $limit, int $offset = 0, ?Account $only = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect("CASE WHEN c.status IN (:closed) THEN 1 ELSE 0 END AS HIDDEN done_sort")
            ->setParameter('closed', Complaint::CLOSED_STATUSES)
            ->orderBy('done_sort', 'ASC')
            ->addOrderBy('c.created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($only instanceof Account) {
            $qb->andWhere('c.account = :only')->setParameter('only', $only);
        }

        return $qb->getQuery()->getResult();
    }

    public function countAll(?Account $only = null): int
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c.id)');

        if ($only instanceof Account) {
            $qb->andWhere('c.account = :only')->setParameter('only', $only);
        }

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    public function countOpen(?Account $only = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.status NOT IN (:closed)')
            ->setParameter('closed', Complaint::CLOSED_STATUSES);

        if ($only instanceof Account) {
            $qb->andWhere('c.account = :only')->setParameter('only', $only);
        }

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    public function findByToken(string $token): ?Complaint
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.photo_token = :t')
            ->setParameter('t', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Everything one flat reported, newest first — the admin table's per-account view.
     *
     * @return Complaint[]
     */
    public function findByAccount(Account $account): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.account = :a')
            ->setParameter('a', $account)
            ->orderBy('c.created_at', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Settled complaints — done or rejected — whose retention month is up.
     *
     * Measured from status_changed_at — the day it was closed — not from when it was
     * filed: a problem reported in January and fixed in June is worth keeping until July.
     * A rejected duplicate is on the same clock: long enough to answer «а чому мою
     * заявку прибрали», short enough that it is not a permanent exhibit.
     *
     * @return Complaint[]
     */
    public function findExpiredClosed(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status IN (:closed)')
            ->andWhere('c.status_changed_at < :before')
            ->setParameter('closed', Complaint::CLOSED_STATUSES)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /**
     * Open complaints nobody has touched since $before.
     *
     * @return Complaint[]
     */
    public function findStaleOpen(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status NOT IN (:closed)')
            ->andWhere('c.status_changed_at < :before')
            ->setParameter('closed', Complaint::CLOSED_STATUSES)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /** @return Complaint[] */
    public function findAllNewestFirst(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.created_at', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
