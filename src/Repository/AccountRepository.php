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
     * Every object in the house, with its owners already loaded.
     *
     * The register of *objects*, as opposed to /admin/users which is the register of
     * *people*. A flat, a parking space and a storage room are three separate accounts,
     * and until this page existed the only way to look at one was to find somebody linked
     * to it — which meant an object with no linked resident was invisible, and those are
     * exactly the ones nobody is chasing for the debt.
     *
     * Ordered the way a person walks the ЖК: by building, then by unit. `apartment_number`
     * is a text column carrying both "85" and "Паркінг 138", so the numeric sort is done in
     * PHP by the caller rather than pretended at in SQL.
     *
     * @return Account[]
     */
    public function findAllWithOwners(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.users', 'u')
            ->addSelect('u')
            ->orderBy('a.house_number', 'ASC')
            ->addOrderBy('a.account_number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The buildings that actually have accounts, in walking order.
     *
     * Feeds the per-building filters on both admin registers. Read from the data rather
     * than hardcoded as 17/19/21/23/27: that list is already wrong — there is a буд. 25 —
     * and it would silently drop a sixth building the day one appears.
     *
     * @return string[]
     */
    public function distinctHouseNumbers(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.house_number AS house')
            ->andWhere("a.house_number <> ''")
            ->getQuery()
            ->getScalarResult();

        $houses = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string)$row['house']),
            $rows,
        )));

        usort($houses, 'strnatcmp');

        return $houses;
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
