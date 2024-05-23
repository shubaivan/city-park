<?php

namespace App\Repository;

use App\Entity\TelegramUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @extends ServiceEntityRepository<TelegramUser>
 *
 * @method TelegramUser|null find($id, $lockMode = null, $lockVersion = null)
 * @method TelegramUser|null findOneBy(array $criteria, array $orderBy = null)
 * @method TelegramUser[]    findAll()
 * @method TelegramUser[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TelegramUserRepository extends ServiceEntityRepository
{
    use DataTablesApproachRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramUser::class);
    }

    public function getByTelegramId(string $telegramId): ?TelegramUser
    {
        return $this->createQueryBuilder('tu')
            ->where('tu.telegram_id = :telegram_id')
            ->setParameter('telegram_id', $telegramId)
            ->getQuery()->getOneOrNullResult();
    }

    public function save(TelegramUser $telegramUser)
    {
        $this->getEntityManager()->persist($telegramUser);
        $this->getEntityManager()->flush();
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
                FROM App\Entity\TelegramUser b
                LEFT JOIN b.account as a
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
                b.phone_number,
                b.additional_phones,
                b.first_name,
                b.last_name,
                b.username,
                date_format(b.created_at, \'%Y-%m-%d %H:%i:%s\') as start,
                date_format(b.updated_at, \'%Y-%m-%d %H:%i:%s\') as last_visit,
                \'edit\' as action
                FROM App\Entity\TelegramUser b
                LEFT JOIN b.account as a
            ';
        }

        $bindParams = [];
        $condition = ' WHERE ';
        $conditions = [];
        if ($parameterBag->get('search') && !$total) {
            $or[] = 'ILIKE(b.username, :var_search) = TRUE';
            $or[] = 'ILIKE(b.first_name, :var_search) = TRUE';
            $or[] = 'ILIKE(b.last_name, :var_search) = TRUE';
            $or[] = 'ILIKE(b.phone_number, :var_search) = TRUE';
            $or[] = 'ILIKE(a.account_number, :var_search) = TRUE';
            $or[] = 'ILIKE(a.apartment_number, :var_search) = TRUE';
            $or[] = 'ILIKE(a.house_number, :var_search) = TRUE';
            $or[] = 'ILIKE(a.street, :var_search) = TRUE';

            $bindParams['var_search'] = '%'.$parameterBag->get('search').'%';
            $conditions[] = '(' . implode(' OR ', $or) .')';

        }

        if (count($conditions)) {
            $conditions = array_unique($conditions);
            $dql .= $condition . implode(' AND ', $conditions);
        }

        if (!$count) {
            $sortByColumn = '';
            if (in_array($sortBy, ['id', 'phone_number', 'first_name', 'last_name', 'username'])) {
                $sortByColumn = 'b.';
            } else if (in_array($sortBy, ['account_number', 'apartment_number', 'house_number', 'street', 'is_active'])) {
                $sortByColumn = 'a.';
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

    public function getUserInfoById(int $id): ?array
    {
        return $this->createQueryBuilder('tu')
            ->select('
                tu.id,   
                a.account_number,
                a.apartment_number,
                a.house_number,
                a.street,                   
                a.is_active,                   
                tu.phone_number,
                tu.additional_phones,
                tu.first_name,
                tu.last_name,
                tu.username,
                date_format(tu.created_at, \'%Y-%m-%d %H:%i:%s\') as start,
                date_format(tu.updated_at, \'%Y-%m-%d %H:%i:%s\') as last_visit
            ')
            ->leftJoin('tu.account', 'a')
            ->where('tu.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
