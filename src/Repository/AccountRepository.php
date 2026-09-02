<?php

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Every Account that shares an owner-group with the given account, including itself.
     * Ungrouped accounts (owner_group_id IS NULL) return just themselves.
     *
     * @return Account[]
     */
    public function findGroupSiblings(Account $account): array
    {
        if ($account->getOwnerGroupId() === null) {
            return [$account];
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.owner_group_id = :gid')
            ->setParameter('gid', $account->getOwnerGroupId())
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Accounts owing money, largest debt first.
     *
     * `debt` is a DECIMAL column, so the sort is numeric and 90 does not land above
     * 12269 the way a string sort would. Ties break on apartment number to keep the
     * board's order stable between renders — two neighbours owing the same amount
     * should not swap places every time somebody opens the menu.
     *
     * @return Account[]
     */
    public function findDebtors(int $limit = 0): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.debt IS NOT NULL')
            ->andWhere('a.debt > 0')
            ->orderBy('a.debt', 'DESC')
            ->addOrderBy('a.apartment_number', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{total: float, debtors: int}
     */
    public function debtTotals(): array
    {
        $row = $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.debt), 0) AS total', 'COUNT(a.id) AS debtors')
            ->andWhere('a.debt IS NOT NULL')
            ->andWhere('a.debt > 0')
            ->getQuery()
            ->getSingleResult();

        return ['total' => (float)$row['total'], 'debtors' => (int)$row['debtors']];
    }

    /**
     * Newest debt-import stamp across all accounts — i.e. when the board's numbers
     * were last confirmed against the accountant's file. NULL when nothing was ever
     * imported since the column existed.
     */
    public function lastDebtImportAt(): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('a')
            ->select('MAX(a.debt_updated_at)')
            ->getQuery()
            ->getSingleScalarResult();

        if (!$value) {
            return null;
        }

        return $value instanceof \DateTimeImmutable ? $value : new \DateTimeImmutable((string)$value);
    }
}
