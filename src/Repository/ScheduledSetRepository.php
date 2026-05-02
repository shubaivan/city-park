<?php

namespace App\Repository;

use App\Entity\Account;
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
    use DataTablesApproachRepository;

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
    public function getByParams(string $pavilion, int $year, int $month, int $day, ?int $hour, ?TelegramUser $user = null): array
    {
        $qb = $this->createQueryBuilder('ss');
        $qb->andWhere('ss.pavilion = :pavilion')->setParameter('pavilion', $pavilion);
        $qb->andWhere('ss.year = :year')->setParameter('year', $year);
        $qb->andWhere('ss.month = :month')->setParameter('month', $month);
        $qb->andWhere('ss.day = :day')->setParameter('day', $day);
        $qb->andWhere('ss.scheduled_at >= :now');
        $qb->setParameter('now', SchedulePavilionService::createNewDate());

        if ($hour) {
            $qb->andWhere('ss.hour = :hour')->setParameter('hour', $hour);
        }

        if ($user) {
            $qb->andWhere('ss.telegramUserId = :user')->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return ScheduledSet[]
     */
    public function findByDayForAccount(
        int $year, int $month, int $day, Account $account
    ): array {
        $qb = $this->createQueryBuilder('ss');
        $qb
            ->join('ss.telegramUserId', 'tu')
            ->andWhere('ss.year = :year')->setParameter('year', $year)
            ->andWhere('ss.month = :month')->setParameter('month', $month)
            ->andWhere('ss.day = :day')->setParameter('day', $day)
            ->andWhere('tu.account = :account')->setParameter('account', $account)
            ->andWhere('ss.scheduled_at >= :now')
            ->setParameter('now', SchedulePavilionService::createNewDate())
            ->orderBy('ss.hour', 'ASC')
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return ScheduledSet[]
     */
    public function findByMonthForAccount(
        \DateTimeInterface $from, \DateTimeInterface $to, Account $account
    ): array {
        $qb = $this->createQueryBuilder('ss');
        $qb
            ->join('ss.telegramUserId', 'tu')
            ->andWhere('tu.account = :account')->setParameter('account', $account)
            ->andWhere($qb->expr()->between('ss.scheduled_at', ':from', ':to'))
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('ss.scheduled_at', 'ASC')
        ;

        return $qb->getQuery()->getResult();
    }

    public function findOverlapForAccount(
        int $year, int $month, int $day, int $hour, Account $account, ?int $excludeId = null
    ): ?ScheduledSet {
        $qb = $this->createQueryBuilder('ss');
        $qb
            ->join('ss.telegramUserId', 'tu')
            ->andWhere('ss.year = :year')->setParameter('year', $year)
            ->andWhere('ss.month = :month')->setParameter('month', $month)
            ->andWhere('ss.day = :day')->setParameter('day', $day)
            ->andWhere('ss.hour = :hour')->setParameter('hour', $hour)
            ->andWhere('tu.account = :account')->setParameter('account', $account)
            ->setMaxResults(1);

        if ($excludeId !== null) {
            $qb->andWhere('ss.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return int[] Hours (0-23) already booked by this account on the given day, across all pavilions.
     */
    public function getBookedHoursForAccount(
        int $year, int $month, int $day, Account $account
    ): array {
        $qb = $this->createQueryBuilder('ss');
        $qb
            ->select('DISTINCT ss.hour AS hour')
            ->join('ss.telegramUserId', 'tu')
            ->andWhere('ss.year = :year')->setParameter('year', $year)
            ->andWhere('ss.month = :month')->setParameter('month', $month)
            ->andWhere('ss.day = :day')->setParameter('day', $day)
            ->andWhere('tu.account = :account')->setParameter('account', $account);

        return array_map(static fn(array $r): int => (int)$r['hour'], $qb->getQuery()->getScalarResult());
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
            ->andWhere('ss.scheduled_at >= :now')
            ->setParameter('now', SchedulePavilionService::createNewDate())
            ->orderBy('ss.pavilion')
            ->addOrderBy('ss.scheduled_at', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function getById(int $id): ?ScheduledSet
    {
        $qb = $this->createQueryBuilder('ss');
        $qb->andWhere('ss.id = :id')->setParameter('id', $id);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @param array $params
     * @param bool $count
     * @param bool $total
     * @return mixed
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getDataTablesData(
        array $params,
        bool $count = false,
        bool $total = false
    )
    {
        $parameterBag = $this->handleDataTablesRequest($params);

        $limit = $parameterBag->get('limit');
        $offset = $parameterBag->get('offset');
        $sortBy = $parameterBag->get('sort_by');
        $sortOrder = $parameterBag->get('sort_order');

        if ($count) {
            $dql = '
                SELECT COUNT(DISTINCT b)
                FROM App\Entity\ScheduledSet b
                LEFT JOIN b.telegramUserId as tu
                LEFT JOIN tu.account as a
            ';
        } else {
            $dql = '
                SELECT 
                b.id,   
                a.account_number,
                a.apartment_number,
                a.house_number,
                a.street,                   
                a.is_active,                   
                tu.phone_number,
                tu.username,
                b.pavilion,
                date_format(b.scheduled_at, \'%Y-%m-%d %H:%i:%s\') as scheduled_at
                FROM App\Entity\ScheduledSet b
                LEFT JOIN b.telegramUserId as tu
                LEFT JOIN tu.account as a
            ';
        }

        $bindParams = [];
        $condition = ' WHERE ';
        $conditions = [];
        if ($parameterBag->get('search') && !$total) {

            try {
                $searchByDate = false;
                if (\DateTime::createFromFormat('Y-m-d', trim($parameterBag->get('search'))) !== false) {
                    $dateTime = \DateTime::createFromFormat('Y-m-d', trim($parameterBag->get('search')));
                    $or[] = 'b.scheduled_at between :from and :to';
                    $bindParams['from'] = (clone $dateTime)->setTime(0,0)->format('Y-m-d H:i:s');
                    $bindParams['to'] = (clone $dateTime)->setTime(23,59)->format('Y-m-d H:i:s');
                    $searchByDate = true;
                }
            } catch (\Throwable $t) {
                $searchByDate = false;
            }

            if (!$searchByDate) {
                $or[] = 'ILIKE(tu.username, :var_search) = TRUE';
                $or[] = 'ILIKE(tu.first_name, :var_search) = TRUE';
                $or[] = 'ILIKE(tu.last_name, :var_search) = TRUE';
                $or[] = 'ILIKE(tu.phone_number, :var_search) = TRUE';
                $or[] = 'ILIKE(a.account_number, :var_search) = TRUE';
                $or[] = 'ILIKE(a.apartment_number, :var_search) = TRUE';
                $or[] = 'ILIKE(a.house_number, :var_search) = TRUE';
                $or[] = 'ILIKE(a.street, :var_search) = TRUE';

                $bindParams['var_search'] = '%'.$parameterBag->get('search').'%';
            }

            $conditions[] = '(' . implode(' OR ', $or) .')';
        }

        if (count($conditions)) {
            $conditions = array_unique($conditions);
            $dql .= $condition . implode(' AND ', $conditions);
        }

        if (!$count) {
            $sortByColumn = '';
            if (in_array($sortBy, ['phone_number', 'username'])) {
                $sortByColumn = 'tu.';
            } else if (in_array($sortBy, ['account_number', 'apartment_number', 'house_number', 'street', 'is_active'])) {
                $sortByColumn = 'a.';
            } else if (in_array($sortBy, ['id', 'pavilion', 'scheduled_at'])) {
                $sortByColumn = 'b.';
            }

            $sortByColumn .= $sortBy;
            $dql .= '
                ORDER BY ' . $sortByColumn . ' ' . $sortOrder;
        }

        $query = $this->getEntityManager()
            ->createQuery($dql);
        if (!$count) {
            $query
                ->setMaxResults($limit)
                ->setFirstResult($offset);
        }

        if ($bindParams) {
            $bindParams = array_unique($bindParams);
            $query
                ->setParameters($bindParams);
        }
        if ($count) {
            $result = $query->getSingleScalarResult();
        } else {
            $result = $query->getResult();
        }

        return $result;
    }
}
