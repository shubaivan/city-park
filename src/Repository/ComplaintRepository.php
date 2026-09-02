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
    public function findForList(int $limit, int $offset = 0): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect("CASE WHEN c.status = :done THEN 1 ELSE 0 END AS HIDDEN done_sort")
            ->setParameter('done', Complaint::STATUS_DONE)
            ->orderBy('done_sort', 'ASC')
            ->addOrderBy('c.created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int)$this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOpen(): int
    {
        return (int)$this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.status <> :done')
            ->setParameter('done', Complaint::STATUS_DONE)
            ->getQuery()
            ->getSingleScalarResult();
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
     * Finished complaints whose retention month is up.
     *
     * Measured from status_changed_at — the day it was closed — not from when it was
     * filed: a problem reported in January and fixed in June is worth keeping until July.
     *
     * @return Complaint[]
     */
    public function findExpiredDone(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :done')
            ->andWhere('c.status_changed_at < :before')
            ->setParameter('done', Complaint::STATUS_DONE)
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
            ->andWhere('c.status <> :done')
            ->andWhere('c.status_changed_at < :before')
            ->setParameter('done', Complaint::STATUS_DONE)
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
