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
}
