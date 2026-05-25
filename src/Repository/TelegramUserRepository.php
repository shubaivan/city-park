<?php

namespace App\Repository;

use App\Entity\Account;
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
     * Find the Account a phone number belongs to as a "умовний власник"
     * (conditional owner). Conditional owners are stored as additional_phones
     * entries on an account holder's TelegramUser record, so a family member
     * using their own Telegram account has no account of their own — we match
     * their confirmed phone against those entries.
     */
    public function findAccountByConditionalPhone(?string $phone): ?Account
    {
        $needle = $this->normalizePhone($phone);
        if ($needle === '') {
            return null;
        }

        /** @var TelegramUser[] $holders */
        $holders = $this->createQueryBuilder('tu')
            ->andWhere('tu.account IS NOT NULL')
            ->andWhere('tu.additional_phones IS NOT NULL')
            ->getQuery()
            ->getResult();

        foreach ($holders as $holder) {
            foreach ($holder->getAdditionalPhones() as $entry) {
                $value = is_array($entry) ? ($entry['property_value'] ?? null) : null;
                if ($value !== null && $this->normalizePhone($value) === $needle) {
                    return $holder->getAccount();
                }
            }
        }

        return null;
    }

    /**
     * Reduce a phone number to its last 9 digits so values entered in the
     * admin panel ("380...", "+380...", "0...") match the format Telegram
     * reports for a shared contact. Returns '' for anything too short to be
     * a real number.
     */
    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : '';
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
                a.debt,
                a.area,
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

        if (isset($params['debt_filter']) && $params['debt_filter'] === '1' && !$total) {
            $conditions[] = 'a.debt > 0';
        }

        if (!$total && !empty($params['account_number_filter'])) {
            $conditions[] = 'a.account_number = :exact_account_number';
            $bindParams['exact_account_number'] = trim((string)$params['account_number_filter']);
        }

        if (count($conditions)) {
            $conditions = array_unique($conditions);
            $dql .= $condition . implode(' AND ', $conditions);
        }

        if (!$count) {
            $sortByColumn = '';
            if (in_array($sortBy, ['id', 'phone_number', 'first_name', 'last_name', 'username'])) {
                $sortByColumn = 'b.';
            } else if (in_array($sortBy, ['account_number', 'apartment_number', 'house_number', 'street', 'is_active', 'debt'])) {
                $sortByColumn = 'a.';
            }

            $sortByColumn .= $sortBy;
            if ($sortBy === 'debt') {
                $dql .= '
                ORDER BY CASE WHEN a.debt IS NULL THEN 0 ELSE a.debt END ' . $sortOrder;
            } else {
                $dql .= '
                ORDER BY ' . $sortByColumn . ' ' . $sortOrder;
            }
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
                a.id as account_id,
                a.account_number,
                a.apartment_number,
                a.house_number,
                a.street,
                a.is_active,
                a.debt,
                a.area,
                a.owner_group_id,
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
