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
     * Build the WHERE conditions (and their bound values) for the /admin/users table.
     *
     * Extracted from getDataTablesData() so the filter rules can be tested without a
     * database: the two bugs this guards against were both invisible to every existing
     * test because they lived inside a method that immediately ran its own query.
     * See tests/Repository/AdminUserSearchTest.
     *
     * @param array<string, mixed> $params
     * @return array{0: string[], 1: array<string, mixed>} conditions, bound values
     */
    public static function buildDataTablesFilters(
        array $params,
        ?string $globalSearch = null,
        bool $total = false
    ): array
    {
        $bindParams = [];
        $conditions = [];


        // Anyone who has ever pressed /start has a TelegramUser row — 274 of them by
        // 02.09.2026, against 175 people actually linked to a flat. Between 02.09 and
        // 03.09.2026 the table hid the unlinked by default (Людмила asked: rows with no
        // о/р and no address read as "who are all these people?"), and that default was
        // reverted after it cost the ОСББ a public argument.
        //
        // On 03.09.2026 Аліна searched a resident's phone in this table, found nothing
        // and told him «немає номера в базі». His row had been there for an hour and a
        // half — unlinked, therefore invisible. She refused to add him without an
        // identification he would not give, he escalated in the residents' chat, and the
        // entire dispute was this WHERE clause. A default that hides rows turns "I did
        // not find him" into "he is not in the system", and nobody can tell the
        // difference from the outside.
        //
        // So the table shows EVERYONE by default. Both halves stay available as explicit
        // filters — «Підтверджені мешканці» is Людмила's view, kept as one click.
        // Guarded by !$total like every other filter. $total answers "how many rows does
        // this table have at all" — DataTables prints it as «filtered from N total
        // entries», the denominator the filtered count is measured against, and a
        // denominator that moves with the filter measures nothing. These two used to sit
        // outside the guard, so «Чекають прив'язки» reported "from 268" while «Боржники»
        // reported "from 449" — the same table, two different sizes.
        if (!$total && ($params['status_filter'] ?? null) === 'unlinked') {
            $conditions[] = 'a.id IS NULL';
        } elseif (!$total && ($params['status_filter'] ?? null) === 'linked') {
            $conditions[] = 'a.id IS NOT NULL';
        }

        if ($globalSearch && !$total) {
            $or[] = 'ILIKE(b.username, :var_search) = TRUE';
            $or[] = 'ILIKE(b.first_name, :var_search) = TRUE';
            $or[] = 'ILIKE(b.last_name, :var_search) = TRUE';
            $or[] = 'ILIKE(b.phone_number, :var_search) = TRUE';
            $or[] = 'ILIKE(a.account_number, :var_search) = TRUE';
            $or[] = 'ILIKE(a.apartment_number, :var_search) = TRUE';
            $or[] = 'ILIKE(a.house_number, :var_search) = TRUE';
            $or[] = 'ILIKE(a.street, :var_search) = TRUE';

            // A username is copied out of Telegram with its @, but stored without one.
            // Pasting "@mi_polina28" into the search box is the obvious thing to do and
            // used to return nothing at all.
            $bindParams['var_search'] = '%'.ltrim((string)$globalSearch, '@').'%';
            $conditions[] = '(' . implode(' OR ', $or) .')';

        }

        // Single mutually-exclusive status filter — see telegram_users.js.
        // Legacy debt_filter / photo_blocked_filter / blocked_filter params are
        // ignored; the UI now sends `status_filter` with one of:
        // debt / photo_blocked / debt_blocked / blocked.
        if (!$total && !empty($params['status_filter']) && $params['status_filter'] !== 'all') {
            switch ($params['status_filter']) {
                case 'unlinked':
                case 'linked':
                    // Both handled above, before the search block: they are the two filters
                    // that change which rows exist at all rather than narrowing them.
                    break;
                case 'debt':
                    $conditions[] = 'a.debt > 0';
                    break;
                case 'photo_blocked':
                    $conditions[] = 'a.is_active = false';
                    $conditions[] = 'a.id IN (
                        SELECT IDENTITY(r.account) FROM App\Entity\PhotoUploadRequest r
                        WHERE r.resolved_at IS NULL AND r.blocked_at IS NOT NULL
                    )';
                    break;
                case 'debt_blocked':
                    // Mirrors DebtPolicy::getThresholdFor: per-account threshold is
                    // area * tariff.price_per_meter * 1.5; when area or tariff is
                    // missing/zero, fall back to the env-configured global threshold.
                    $pricePerMeter = (float)($params['_debt_price_per_meter'] ?? 0);
                    $fallback = (float)($params['_debt_fallback_threshold'] ?? 1300);
                    $conditions[] = 'a.is_active = false';
                    if ($pricePerMeter > 0) {
                        $conditions[] = '(
                            (a.area > 0 AND a.debt > a.area * :debt_price * 1.5)
                            OR ((a.area IS NULL OR a.area = 0) AND a.debt > :debt_fallback)
                        )';
                        $bindParams['debt_price'] = $pricePerMeter;
                        $bindParams['debt_fallback'] = $fallback;
                    } else {
                        $conditions[] = 'a.debt > :debt_fallback';
                        $bindParams['debt_fallback'] = $fallback;
                    }
                    break;
                case 'blocked':
                    $conditions[] = 'a.is_active = false';
                    break;
            }
        }

        // 'none' is a real answer, not the absence of one: "хто ще не розібраний" is the
        // question the accountant works through, so it needs its own value rather than
        // being indistinguishable from "фільтр не вибрано".
        if (!$total && !empty($params['role_filter'])) {
            if ($params['role_filter'] === 'none') {
                $conditions[] = 'b.role IS NULL';
            } else {
                $conditions[] = 'b.role = :role_filter';
                $bindParams['role_filter'] = (string)$params['role_filter'];
            }
        }

        if (!$total && !empty($params['account_number_filter'])) {
            $conditions[] = 'a.account_number = :exact_account_number';
            $bindParams['exact_account_number'] = trim((string)$params['account_number_filter']);
        }

        // Per-field ILIKE search — AND'd together so each input narrows the result.
        // The DataTables global "Search:" input stays as a separate OR-across-all
        // quick lookup (handled by the search block above).
        $ilikeFieldMap = [
            'search_last_name'  => 'b.last_name',
            'search_first_name' => 'b.first_name',
            'search_phone'      => 'b.phone_number',
            'search_username'   => 'b.username',
        ];
        foreach ($ilikeFieldMap as $param => $column) {
            if (!$total && !empty($params[$param])) {
                $conditions[] = "ILIKE($column, :$param) = TRUE";
                // ltrim('@') for the same reason as the global search: a username pasted
                // from Telegram carries one, the column does not.
                $bindParams[$param] = '%' . ltrim(trim((string)$params[$param]), '@') . '%';
            }
        }
        if (!$total && !empty($params['search_address'])) {
            $conditions[] = '(ILIKE(a.street, :search_address) = TRUE
                OR ILIKE(a.house_number, :search_address) = TRUE
                OR ILIKE(a.apartment_number, :search_address) = TRUE)';
            $bindParams['search_address'] = '%' . trim((string)$params['search_address']) . '%';
        }

        return [array_unique($conditions), $bindParams];
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
                b.role,
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

        [$conditions, $bindParams] = self::buildDataTablesFilters(
            $params,
            $parameterBag->get('search'),
            $total
        );
        $condition = ' WHERE ';

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
            // NOT array_unique(): these are keyed placeholders, and array_unique
            // deduplicates by VALUE. Typing the same phone into the global "Search:"
            // box and the «Телефон» column produced two identical values, one of the
            // two keys was dropped, and the query went to Doctrine with a placeholder
            // nothing was bound to — an exception, a 500, and "DataTables warning:
            // Ajax error" in the admin's face (seen 03.09.2026). Duplicate values are
            // normal here; duplicate keys are impossible.
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
                tu.role,
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
