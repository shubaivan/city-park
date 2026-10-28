<?php

namespace App\Repository;

use App\Entity\PowerSchedulePost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PowerSchedulePost>
 */
class PowerSchedulePostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PowerSchedulePost::class);
    }

    public function forDay(\DateTimeImmutable $day): ?PowerSchedulePost
    {
        return $this->findOneBy(['day' => $day->setTime(0, 0)]);
    }

    /**
     * Yesterday, today and tomorrow, by date — what a reminder can belong to: an outage
     * that starts at 00:00 is warned about the evening before, and one that ends at 24:00
     * is over after midnight.
     *
     * @return array<string, PowerSchedulePost>
     */
    public function around(\DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0);
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.day BETWEEN :from AND :to')
            ->setParameter('from', $today->modify('-1 day'))
            ->setParameter('to', $today->modify('+1 day'))
            ->orderBy('p.day', 'ASC')
            ->getQuery()
            ->getResult();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row->getDay()->format('Y-m-d')] = $row;
        }

        return $byDay;
    }
}
