<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;

/**
 * The register of *objects*, as opposed to `/admin/users` which is the register of people.
 *
 * The distinction is the whole point of the page this feeds. In the bot's data model a
 * property is an `Account` and a person is a `TelegramUser`, and the two lists answer
 * different questions:
 *
 * - **A person may own several objects.** A flat, a parking space and a storage room are
 *   three особові рахунки. The accountant links a person to one of them and the rest have
 *   no-one — «у людини може бути 5 об'єктів, а я ж тільки до 1 підв'язую» (Аліна,
 *   03.09.2026). `owner_group_id` is what says those rows are one household.
 * - **An object may have several owners, of different kinds.** Owner, family member,
 *   tenant — `TelegramUser.role`. That side already worked; it was only ever visible from
 *   the person's card, one person at a time.
 *
 * Until this existed there was no way to look at a property at all: you found it by finding
 * somebody linked to it, which made an object with no linked resident invisible — and those
 * are precisely the ones nobody is chasing for the debt.
 */
class PropertyRegistry
{
    /** Aliases of the Account constants, kept so callers need not import the entity. */
    public const TYPE_APARTMENT = Account::UNIT_APARTMENT;
    public const TYPE_PARKING = Account::UNIT_PARKING;
    public const TYPE_STORAGE = Account::UNIT_STORAGE;

    public function __construct(
        private AccountRepository $accounts,
        private DebtPolicy $debtPolicy,
        private BlockReasonResolver $blockReasons,
        private OwnerGroupService $ownerGroups,
    ) {}

    /**
     * Every object, ready to render.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(): array
    {
        $accounts = $this->accounts->findAllWithOwners();

        // Group members are resolved from the set already in memory rather than one query
        // per row: 172 objects would otherwise be 172 round trips for a column that is
        // empty on almost all of them.
        $byGroup = [];

        foreach ($accounts as $account) {
            $groupId = $account->getOwnerGroupId();

            if ($groupId !== null) {
                $byGroup[$groupId][] = $account;
            }
        }

        $rows = [];

        foreach ($accounts as $account) {
            $groupId = $account->getOwnerGroupId();
            $siblings = [];

            foreach ($byGroup[$groupId] ?? [] as $member) {
                if ($member->getId() !== $account->getId()) {
                    $siblings[] = $member;
                }
            }

            $debt = (float)($account->getDebt() ?? 0);
            $threshold = $this->debtPolicy->getThresholdFor($account);

            $rows[] = [
                'account' => $account,
                'type' => $this->type($account),
                'type_label' => $this->typeLabel($account),
                'place' => $this->place($account),
                'owners' => $this->owners($account),
                'debt' => $debt,
                'threshold' => $threshold,
                'over_threshold' => $debt > $threshold,
                'block' => $this->blockReasons->resolve($account),
                'siblings' => $siblings,
                // People on the *other* objects of the same owner. An object can be part of
                // a household and still have nobody registered on it — a комірчина usually
                // does — and «нікого не сповіщають» reads as "abandoned" when in fact the
                // owner is one row away. Both facts matter and they are different facts.
                'group_owners' => array_merge(...array_map(
                    fn (Account $sibling): array => $this->owners($sibling),
                    $siblings,
                ) ?: [[]]),
                // The group's arrears, for the panel only. The bot deliberately does not
                // sum these — each object has its own threshold (area × tariff × 1.5) and
                // one delinquent object blocks the group — but a person asking "скільки
                // винен цей власник" wants one number.
                'group_debt' => array_reduce(
                    $siblings,
                    static fn (float $carry, Account $s): float => $carry + (float)($s->getDebt() ?? 0),
                    $debt,
                ),
            ];
        }

        return $rows;
    }

    /**
     * The buildings that actually have objects, in walking order.
     *
     * Taken from the data rather than hardcoded: the ЖК is five buildings today (Козацька
     * 17, 19, 21, 23, 27) and a hardcoded list is a filter that silently drops a building
     * the day a sixth appears — or shows an empty one after a renumbering.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array{house: string, count: int}>
     */
    public function houses(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $house = trim((string)$row['account']->getHouseNumber());

            if ($house !== '') {
                $counts[$house] = ($counts[$house] ?? 0) + 1;
            }
        }

        uksort($counts, static fn (string $a, string $b): int => strnatcmp($a, $b));

        return array_map(
            static fn (string $house, int $count): array => ['house' => $house, 'count' => $count],
            array_keys($counts),
            $counts,
        );
    }

    /**
     * @return array{objects:int, apartments:int, parking:int, storage:int, unowned:int, grouped:int, debt:float, in_debt:int, multi_owner:int, many_owner:int}
     */
    public function stats(array $rows): array
    {
        $stats = [
            'objects' => count($rows),
            'apartments' => 0,
            'parking' => 0,
            'storage' => 0,
            'unowned' => 0,
            'grouped' => 0,
            'debt' => 0.0,
            'in_debt' => 0,
            // Several people on one object — family, tenants, conditional owners. Worth
            // filtering for: this is where a tenant is registered next to an owner, and
            // where a role is most likely to be wrong or missing.
            'multi_owner' => 0,
            'many_owner' => 0,
        ];

        foreach ($rows as $row) {
            $stats[match ($row['type']) {
                self::TYPE_PARKING => 'parking',
                self::TYPE_STORAGE => 'storage',
                default => 'apartments',
            }]++;

            $owners = count($row['owners']);

            if ($owners === 0) {
                $stats['unowned']++;
            }

            if ($owners >= 2) {
                $stats['multi_owner']++;
            }

            if ($owners >= 3) {
                $stats['many_owner']++;
            }

            if ($row['siblings'] !== []) {
                $stats['grouped']++;
            }

            if ($row['debt'] > 0) {
                $stats['debt'] += $row['debt'];
                $stats['in_debt']++;
            }
        }

        return $stats;
    }

    /**
     * Everything one person owns, their own object first.
     *
     * @return Account[]
     */
    public function objectsOf(?TelegramUser $user): array
    {
        $account = $user?->getAccount();

        if (!$account instanceof Account) {
            return [];
        }

        return [$account, ...$this->ownerGroups->siblings($account)];
    }

    public function type(Account $account): string
    {
        return $account->getUnitType();
    }

    public function typeLabel(Account $account): string
    {
        return $account->getUnitTypeLabel();
    }

    /**
     * "буд. 19, кв. 85" — the same rule as the debtors' board and the rental noticeboard.
     * The ЖК is five buildings on one street and apartment numbers repeat across them, so
     * an object named by its unit alone names two places at once.
     */
    /** Delegates to the entity so the label reads the same wherever it is printed. */
    public function place(Account $account): string
    {
        return $account->getPlaceLabel();
    }

    /** @return TelegramUser[] */
    private function owners(Account $account): array
    {
        $owners = $account->getUsers()->toArray();

        // The registered owner first, then family, then tenants, then whoever has no label
        // — the person the ОСББ actually deals with should not be third in a list of four.
        usort($owners, static function (TelegramUser $a, TelegramUser $b): int {
            $rank = static fn (?string $role): int => match ($role) {
                'owner' => 0,
                'family' => 1,
                'tenant' => 2,
                default => 3,
            };

            return $rank($a->getRole()) <=> $rank($b->getRole());
        });

        return $owners;
    }
}
