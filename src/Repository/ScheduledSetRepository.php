<?php

namespace App\Repository;

use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Service\SchedulePavilionService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledSet>
 */
class ScheduledSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledSet::class);
    }

    /**
     * @param string $pavilion
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int|null $hour
     * @param TelegramUser|null $user
     * @return ScheduledSet[]
     */
    public function getByParams(string $pavilion, int $year, int $month, int $day, ?int $hour, ?TelegramUser $user): array
    {
        $qb = $this->createQueryBuilder('ss');
        $qb->andWhere('ss.pavilion = :pavilion')->setParameter('pavilion', $pavilion);
        $qb->andWhere('ss.year = :year')->setParameter('year', $year);
        $qb->andWhere('ss.month = :month')->setParameter('month', $month);
        $qb->andWhere('ss.day = :day')->setParameter('day', $day);
        $qb->andWhere('ss.scheduledAt >= :now');
        $qb->setParameter('now', SchedulePavilionService::createNewDate());

        if ($hour) {
            $qb->andWhere('ss.hour = :hour')->setParameter('hour', $hour);
        }

        if ($user) {
            $qb->andWhere('ss.telegramUserId = :user')->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    public function countOfSetByParams(int $pavilion, int $year, int $month, int $day, TelegramUser $user)
    {
        $qb = $this->createQueryBuilder('ss');
        $qb->select('COUNT(ss.id)');
        $qb->andWhere('ss.pavilion = :pavilion')->setParameter('pavilion', $pavilion);
        $qb->andWhere('ss.year = :year')->setParameter('year', $year);
        $qb->andWhere('ss.month = :month')->setParameter('month', $month);
        $qb->andWhere('ss.day = :day')->setParameter('day', $day);
        $qb->andWhere('ss.telegramUserId = :user')->setParameter('user', $user);
        $qb->andWhere('ss.scheduledAt >= :now');
        $qb->setParameter('now', SchedulePavilionService::createNewDate());

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param TelegramUser $user
     * @return ScheduledSet[]
     */
    public function getOwn(TelegramUser $user): array
    {
        $qb = $this->createQueryBuilder('ss');
        $qb
            ->andWhere('ss.telegramUserId = :user')
            ->setParameter('user', $user)
            ->andWhere('ss.scheduledAt >= :now')
            ->setParameter('now', SchedulePavilionService::createNewDate())
            ->orderBy('ss.pavilion')
        ;

        return $qb->getQuery()->getResult();
    }

    public function getById(int $id): ?ScheduledSet
    {
        $qb = $this->createQueryBuilder('ss');
        $qb->andWhere('ss.id = :id')->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
