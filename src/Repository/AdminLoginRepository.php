<?php

namespace App\Repository;

use App\Entity\AdminLogin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminLogin>
 */
class AdminLoginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminLogin::class);
    }

    /** @return AdminLogin[] */
    public function findRecent(int $limit = 200): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.at', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The last successful sign-in per login — the summary line above the list.
     *
     * Read from the same table rather than kept as a column on the user, because the
     * users are four entries in security.yaml with nowhere to keep one.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function lastSeenByLogin(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.login AS login', 'MAX(l.at) AS seen')
            ->andWhere('l.success = true')
            ->groupBy('l.login')
            ->getQuery()
            ->getArrayResult();

        $out = [];

        foreach ($rows as $row) {
            $seen = $row['seen'];
            $out[(string)$row['login']] = $seen instanceof \DateTimeImmutable
                ? $seen
                : new \DateTimeImmutable((string)$seen);
        }

        arsort($out);

        return $out;
    }

    public function countFailedSince(\DateTimeImmutable $since): int
    {
        return (int)$this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.success = false')
            ->andWhere('l.at >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
