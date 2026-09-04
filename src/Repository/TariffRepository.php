<?php

namespace App\Repository;

use App\Entity\Tariff;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tariff>
 */
class TariffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tariff::class);
    }

    /**
     * Get-or-create the single tariff row. We treat the tariff table as a one-row
     * singleton; if it's somehow missing, create a zeroed row so callers always
     * have something to read.
     */
    /**
     * The tariff is one row that changes about once a year, and it is asked for per
     * account: DebtPolicy::getThresholdFor() calls this to compute area × price × 1.5, so
     * rendering the objects register ran the same SELECT 173 times, and the residents'
     * table ten times a page. `pg_stat_user_tables` showed 15 334 index scans of a
     * single-row table.
     *
     * Held for the request only. A tariff edited in /admin/tariff writes through this same
     * repository instance in that request, and the next request starts empty — so nothing
     * can serve a stale price to the person who just changed it.
     */
    private ?Tariff $cached = null;

    public function getOrCreate(EntityManagerInterface $em): Tariff
    {
        if ($this->cached instanceof Tariff) {
            return $this->cached;
        }

        $row = $this->createQueryBuilder('t')
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row instanceof Tariff) {
            return $this->cached = $row;
        }

        $row = new Tariff();
        $em->persist($row);
        $em->flush();

        return $this->cached = $row;
    }
}
