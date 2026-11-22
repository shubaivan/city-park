<?php

namespace App\Repository;

use App\Entity\AdminActionLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminActionLog>
 */
class AdminActionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminActionLog::class);
    }

    /**
     * The journal page: newest first, filtered.
     *
     * @param array{actor?: ?string, accounts?: list<int>, user?: ?int, from?: ?\DateTimeImmutable, to?: ?\DateTimeImmutable, q?: ?string} $filters
     * @return array{rows: list<AdminActionLog>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('a');
        $this->applyFilters($qb, $filters);

        $total = (int)(clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $rows = $qb
            ->orderBy('a.at', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<AdminActionLog> the latest rows about one object, including people who left it */
    public function forAccount(int $accountId, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.accountId = :id OR a.prevAccountId = :id OR a.id IN (:bulk)')
            ->setParameter('id', $accountId)
            ->setParameter('bulk', $this->bulkRowsFor([$accountId]) ?: [0])
            ->orderBy('a.at', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<AdminActionLog> */
    public function forUser(int $userId, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userId = :id')
            ->setParameter('id', $userId)
            ->orderBy('a.at', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everybody who appears in the journal, for the filter. Bot users fold into one «bot»
     * entry: a list of Telegram ids would be useless as a dropdown.
     *
     * @return list<string>
     */
    public function actors(): array
    {
        $raw = $this->createQueryBuilder('a')
            ->select('DISTINCT a.actor')
            ->getQuery()
            ->getSingleColumnResult();

        $out = [];

        foreach ($raw as $actor) {
            $actor = (string)$actor;
            $out[$actor === 'bot' || str_starts_with($actor, 'bot:') ? 'bot' : $actor] = true;
        }

        $actors = array_keys($out);
        sort($actors);

        return $actors;
    }

    /**
     * «Масова зміна» rows (the debt upload, the area import) that include these objects.
     * They have no account_id — one row covers hundreds — so they are found inside the
     * {field: {id: [old, new]}} map. Few such rows exist (one per upload), so this is cheap.
     *
     * @param list<int> $accountIds
     * @return list<int>
     */
    public function bulkRowsFor(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        try {
            return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
                "SELECT l.id FROM admin_action_log l
                  WHERE l.account_id IS NULL AND l.target_type = 'account' AND l.summary LIKE 'Масова зміна:%'
                    AND EXISTS (SELECT 1 FROM json_each(l.changes) f, json_object_keys(f.value) k
                                 WHERE json_typeof(f.value) = 'object' AND k = ANY(string_to_array(:ids, ',')))
                  ORDER BY l.id DESC LIMIT 50",
                ['ids' => implode(',', array_map('intval', $accountIds))],
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        $actor = trim((string)($filters['actor'] ?? ''));

        if ($actor === 'bot') {
            $qb->andWhere("a.actor = 'bot' OR a.actor LIKE :actor")->setParameter('actor', 'bot:%');
        } elseif ($actor !== '') {
            $qb->andWhere('a.actor = :actor')->setParameter('actor', $actor);
        }

        if (isset($filters['accounts'])) {
            // An object filter that matched nothing must show nothing, not everything.
            $ids = $filters['accounts'] !== [] ? $filters['accounts'] : [0];
            $qb->andWhere('a.accountId IN (:accounts) OR a.prevAccountId IN (:accounts) OR a.id IN (:bulk)')
                ->setParameter('accounts', $ids)
                ->setParameter('bulk', $this->bulkRowsFor($ids) ?: [0]);
        }

        if (!empty($filters['user'])) {
            $qb->andWhere('a.userId = :user')->setParameter('user', (int)$filters['user']);
        }

        if (($filters['from'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('a.at >= :from')->setParameter('from', $filters['from']->setTime(0, 0));
        }

        if (($filters['to'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('a.at < :to')->setParameter('to', $filters['to']->setTime(0, 0)->modify('+1 day'));
        }

        $q = trim((string)($filters['q'] ?? ''));

        if ($q !== '') {
            $qb->andWhere('LOWER(a.summary) LIKE :q OR LOWER(a.actor) LIKE :q OR LOWER(a.path) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower(addcslashes($q, '%_\\')) . '%');
        }
    }
}
