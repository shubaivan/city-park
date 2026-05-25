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
    public function getOrCreate(EntityManagerInterface $em): Tariff
    {
        $row = $this->createQueryBuilder('t')
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row instanceof Tariff) {
            return $row;
        }

        $row = new Tariff();
        $em->persist($row);
        $em->flush();
        return $row;
    }
}
