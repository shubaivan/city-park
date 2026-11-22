<?php

namespace App\Service\ActionLog;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Entity\ExpectedResident;
use App\Entity\GuestPass;
use App\Entity\TelegramUser;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Old → new for the fields that decide who lives where, who is blocked and who owes —
 * whoever changed them: the panel, the bot or a console command.
 *
 * The request row (AdminActionSubscriber) says which button was pressed; this says what
 * it actually did, and it also catches what never went through the panel. Built on the
 * flush, written after it (postFlush) so that a new row already has its id and a failed
 * flush leaves no row claiming something happened.
 *
 * **Bulk changes are one row.** When one flush changes the same field on more than
 * BULK_THRESHOLD rows — the monthly debt upload touches several hundred accounts, the area
 * import nearly all 966 — they become a single row with the count and a compact
 * {id: [old, new]} map, instead of hundreds of lines nobody will read on the page.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class AdminChangeListener
{
    public const BULK_THRESHOLD = 20;

    /** Account fields worth a row. */
    private const ACCOUNT_FIELDS = [
        'owner_group_id', 'debt', 'is_active', 'blocked_until', 'area',
        'unit_type', 'account_number', 'house_number', 'apartment_number', 'street',
    ];

    private const USER_FIELDS = ['account', 'role', 'full_name', 'phone_number', 'additional_phones'];

    /** Field names the way the bulk row says them. */
    private const FIELD_LABELS = [
        'owner_group_id' => 'група власника',
        'debt' => 'борг',
        'is_active' => 'блок',
        'blocked_until' => 'блок голосуванням',
        'area' => 'площа',
        'unit_type' => 'тип об’єкта',
        'account_number' => 'особовий рахунок',
        'house_number' => 'будинок',
        'apartment_number' => 'номер',
        'street' => 'вулиця',
        'account' => 'особовий рахунок',
        'role' => 'роль',
        'full_name' => 'ПІБ',
        'phone_number' => 'телефон',
        'additional_phones' => 'додаткові телефони',
    ];

    /** @var list<array<string, mixed>> rows ready to write */
    private array $rows = [];

    /** @var list<object> new entities whose row needs the id the insert gives them */
    private array $inserted = [];

    public function __construct(private AdminActionJournal $journal) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        // A flush that threw last time never reached postFlush; its rows must not be
        // written as part of this one.
        $this->rows = [];
        $this->inserted = [];

        try {
            $this->collect($args->getObjectManager()->getUnitOfWork());
        } catch (\Throwable) {
            // Never in the way of the flush itself — see AdminActionJournal.
            $this->rows = [];
            $this->inserted = [];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $rows = $this->rows;
        $inserted = $this->inserted;
        $this->rows = [];
        $this->inserted = [];

        // The data is committed by now: nothing here may throw back into flush(), or a
        // Telegram update would answer 500 and be retried into a duplicate.
        try {
            foreach ($inserted as $entity) {
                $row = $this->insertRow($entity);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        } catch (\Throwable) {
        }

        foreach ($rows as $row) {
            $this->journal->record($row);
        }
    }

    private function collect(UnitOfWork $uow): void
    {
        /** @var array<string, array<int, array{0: mixed, 1: mixed}>> $perField "Class.field" => id => [old, new] */
        $perField = [];
        $updates = [];

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Account) {
                $changes = self::watched($uow->getEntityChangeSet($entity), self::ACCOUNT_FIELDS);
            } elseif ($entity instanceof TelegramUser) {
                $changes = self::watched($uow->getEntityChangeSet($entity), self::USER_FIELDS);
            } elseif ($entity instanceof ExpectedResident) {
                $this->expectedUpdate($entity, $uow->getEntityChangeSet($entity));
                continue;
            } elseif ($entity instanceof GuestPass) {
                $this->guestPassUpdate($entity, $uow->getEntityChangeSet($entity));
                continue;
            } else {
                continue;
            }

            if ($changes === []) {
                continue;
            }

            $updates[] = [$entity, $changes];

            // By the real class, not $entity::class: a lazily loaded Account is a proxy
            // subclass and would land in a group of its own.
            $class = $entity instanceof Account ? Account::class : TelegramUser::class;

            foreach ($changes as $field => $pair) {
                $perField[$class . '.' . $field][(int)$entity->getId()] = $pair;
            }
        }

        // Fields changed on more rows than anybody will read: one row for the lot.
        $bulkFields = array_keys(array_filter($perField, static fn (array $ids): bool => count($ids) > self::BULK_THRESHOLD));

        foreach ($bulkFields as $key) {
            [$class, $field] = explode('.', $key, 2);
            $map = [];

            foreach ($perField[$key] as $id => [$old, $new]) {
                $map[(string)$id] = [self::plain($old), self::plain($new)];
            }

            $what = $class === Account::class ? 'об’єктів' : 'мешканців';
            $this->rows[] = [
                'summary' => sprintf('Масова зміна: %s — у %d %s', self::FIELD_LABELS[$field] ?? $field, count($map), $what),
                'target_type' => $class === Account::class ? 'account' : 'telegram_user',
                'changes' => [$field => $map],
            ];
        }

        foreach ($updates as [$entity, $changes]) {
            $class = $entity instanceof Account ? Account::class : TelegramUser::class;

            foreach (array_keys($changes) as $field) {
                if (in_array($class . '.' . $field, $bulkFields, true)) {
                    unset($changes[$field]);
                }
            }

            if ($changes === []) {
                continue;
            }

            $this->rows[] = $entity instanceof Account
                ? self::accountRow($entity, $changes)
                : self::userRow($entity, $changes);
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof ExpectedResident || $entity instanceof CarPlate || $entity instanceof GuestPass) {
                $this->inserted[] = $entity;
            }
        }

        // Built now, while the entity still has its id: the ORM clears it on delete.
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $row = $this->deleteRow($entity);

            if ($row !== null) {
                $this->rows[] = $row;
            }
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @param list<string> $fields
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function watched(array $changeSet, array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            if (!isset($changeSet[$field])) {
                continue;
            }

            [$old, $new] = $changeSet[$field];

            if (self::same($field, $old, $new)) {
                continue;
            }

            $out[$field] = [$old, $new];
        }

        return $out;
    }

    /** «1200.5» and «1200.50» are the same debt; a re-saved value is not a change. */
    private static function same(string $field, mixed $old, mixed $new): bool
    {
        if (in_array($field, ['debt', 'area'], true)) {
            return ($old === null) === ($new === null) && round((float)$old, 2) === round((float)$new, 2);
        }

        if ($field === 'full_name' || $field === 'phone_number') {
            return trim((string)$old) === trim((string)$new);
        }

        return self::plain($old) === self::plain($new);
    }

    /** What goes into the JSON: ids for entities, «2026-11-22 13:54» for dates. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof Account, $value instanceof TelegramUser => $value->getId(),
            is_array($value) => array_values($value),
            default => $value,
        };
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changes */
    private static function accountRow(Account $account, array $changes): array
    {
        $parts = [];

        foreach ($changes as $field => [$old, $new]) {
            $parts[] = match ($field) {
                'owner_group_id' => match (true) {
                    $old === null => sprintf('об’єднано з іншими об’єктами власника (група %d)', $new),
                    $new === null => sprintf('виключено з групи власника %d', $old),
                    default => sprintf('група власника %d → %d', $old, $new),
                },
                'debt' => sprintf('борг %s → %s грн', self::money($old), self::money($new)),
                'is_active' => $new ? 'розблоковано' : 'заблоковано',
                'blocked_until' => $new instanceof \DateTimeInterface
                    ? 'блок голосуванням до ' . $new->format('d.m.Y')
                    : 'блок голосуванням знято',
                'area' => sprintf('площа %s → %s м²', $old ?? '—', $new ?? '—'),
                'unit_type' => sprintf('тип %s → %s', Account::UNIT_TYPES[$old] ?? '—', Account::UNIT_TYPES[$new] ?? '—'),
                default => sprintf('%s: %s → %s', self::FIELD_LABELS[$field] ?? $field, $old ?? '—', $new ?? '—'),
            };
        }

        return [
            'summary' => self::place($account) . ': ' . implode('; ', $parts),
            'target_type' => 'account',
            'target_id' => $account->getId(),
            'account_id' => $account->getId(),
            'changes' => self::plainChanges($changes),
        ];
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changes */
    private static function userRow(TelegramUser $user, array $changes): array
    {
        $parts = [];
        $prevAccount = null;

        foreach ($changes as $field => [$old, $new]) {
            $parts[] = match ($field) {
                'account' => match (true) {
                    $old === null => 'прив’язано до ' . self::place($new),
                    $new === null => 'відв’язано від ' . self::place($old),
                    default => sprintf('перенесено: %s → %s', self::place($old), self::place($new)),
                },
                'role' => sprintf('роль: %s → %s', TelegramUser::ROLES[$old] ?? 'не вказано', TelegramUser::ROLES[$new] ?? 'не вказано'),
                'full_name' => sprintf('ПІБ: «%s» → «%s»', $old ?: '—', $new ?: '—'),
                'phone_number' => sprintf('телефон: %s → %s', $old ?: '—', $new ?: '—'),
                'additional_phones' => sprintf('додаткові телефони: %s → %s', self::phones($old), self::phones($new)),
                default => $field,
            };

            if ($field === 'account' && $old instanceof Account) {
                $prevAccount = $old->getId();
            }
        }

        $current = $user->getAccount();

        return [
            'summary' => sprintf('%s (#%d): %s', $user->getDisplayName(), $user->getId(), implode('; ', $parts)),
            'target_type' => 'telegram_user',
            'target_id' => $user->getId(),
            'user_id' => $user->getId(),
            'account_id' => $current?->getId() ?? $prevAccount,
            'prev_account_id' => $current !== null && $prevAccount !== $current->getId() ? $prevAccount : null,
            'changes' => self::plainChanges($changes),
        ];
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changeSet */
    private function expectedUpdate(ExpectedResident $expected, array $changeSet): void
    {
        [$old, $new] = $changeSet['claimed_by'] ?? [null, null];

        if ($old !== null || !$new instanceof TelegramUser) {
            return;
        }

        $this->rows[] = [
            'summary' => sprintf(
                '%s: %s прийшов(ла) у бот за номером %s — мешканець #%d',
                self::place($expected->getAccount()),
                $expected->getFullName() ?: 'очікуваний мешканець',
                $expected->getPhone(),
                $new->getId(),
            ),
            'target_type' => 'expected_resident',
            'target_id' => $expected->getId(),
            'account_id' => $expected->getAccount()?->getId(),
            'user_id' => $new->getId(),
            'changes' => ['claimed_by' => [null, $new->getId()]],
        ];
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changeSet */
    private function guestPassUpdate(GuestPass $pass, array $changeSet): void
    {
        [$old, $new] = $changeSet['revoked_at'] ?? [null, null];

        if ($old !== null || $new === null) {
            return;
        }

        $this->rows[] = [
            'summary' => sprintf('%s: скасовано пропуск №%d «%s»', self::place($pass->getAccount()), $pass->getId(), $pass->getLabel()),
            'target_type' => 'guest_pass',
            'target_id' => $pass->getId(),
            'account_id' => $pass->getAccount()?->getId(),
            'user_id' => $pass->getIssuedBy()?->getId(),
            'changes' => ['revoked_at' => [null, self::plain($new)]],
        ];
    }

    private function insertRow(object $entity): ?array
    {
        return match (true) {
            $entity instanceof ExpectedResident => [
                'summary' => sprintf('%s: додано в «Очікуємо в боті» — %s, %s', self::place($entity->getAccount()), $entity->getFullName() ?: 'без імені', $entity->getPhone()),
                'target_type' => 'expected_resident',
                'target_id' => $entity->getId(),
                'account_id' => $entity->getAccount()?->getId(),
                'changes' => ['phone' => [null, $entity->getPhone()], 'full_name' => [null, $entity->getFullName()]],
            ],
            $entity instanceof CarPlate => [
                'summary' => sprintf('%s: додано авто %s', self::place($entity->getAccount()), $entity->getDisplay()),
                'target_type' => 'car_plate',
                'target_id' => $entity->getId(),
                'account_id' => $entity->getAccount()?->getId(),
                'user_id' => $entity->getAddedBy()?->getId(),
                'changes' => ['plate' => [null, $entity->getPlate()]],
            ],
            $entity instanceof GuestPass => [
                'summary' => sprintf(
                    '%s: новий пропуск №%d «%s»%s',
                    self::place($entity->getAccount()),
                    $entity->getId(),
                    $entity->getLabel(),
                    $entity->getActiveUntil() ? ' до ' . $entity->getActiveUntil()->format('d.m.Y H:i') : '',
                ),
                'target_type' => 'guest_pass',
                'target_id' => $entity->getId(),
                'account_id' => $entity->getAccount()?->getId(),
                'user_id' => $entity->getIssuedBy()?->getId(),
                'changes' => ['label' => [null, $entity->getLabel()]],
            ],
            default => null,
        };
    }

    private function deleteRow(object $entity): ?array
    {
        return match (true) {
            $entity instanceof ExpectedResident => [
                'summary' => sprintf('%s: прибрано з «Очікуємо в боті» — %s, %s', self::place($entity->getAccount()), $entity->getFullName() ?: 'без імені', $entity->getPhone()),
                'target_type' => 'expected_resident',
                'target_id' => $entity->getId(),
                'account_id' => $entity->getAccount()?->getId(),
                'changes' => ['phone' => [$entity->getPhone(), null], 'full_name' => [$entity->getFullName(), null]],
            ],
            $entity instanceof CarPlate => [
                'summary' => sprintf('%s: видалено авто %s', self::place($entity->getAccount()), $entity->getDisplay()),
                'target_type' => 'car_plate',
                'target_id' => $entity->getId(),
                'account_id' => $entity->getAccount()?->getId(),
                'user_id' => $entity->getAddedBy()?->getId(),
                'changes' => ['plate' => [$entity->getPlate(), null]],
            ],
            $entity instanceof GuestPass => [
                'summary' => sprintf('%s: видалено пропуск №%d «%s»', self::place($entity->getAccount()), $entity->getId(), $entity->getLabel()),
                'target_type' => 'guest_pass',
                'target_id' => $entity->getId(),
                'account_id' => $entity->getAccount()?->getId(),
                'changes' => ['label' => [$entity->getLabel(), null]],
            ],
            $entity instanceof TelegramUser => [
                'summary' => sprintf(
                    'Видалено мешканця %s (#%d)%s',
                    $entity->getDisplayName(),
                    $entity->getId(),
                    $entity->getAccount() ? ', був: ' . self::place($entity->getAccount()) : '',
                ),
                'target_type' => 'telegram_user',
                'target_id' => $entity->getId(),
                'user_id' => $entity->getId(),
                'account_id' => $entity->getAccount()?->getId(),
                'changes' => ['telegram_id' => [$entity->getTelegramId(), null], 'phone_number' => [$entity->getPhoneNumber(), null]],
            ],
            default => null,
        };
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function plainChanges(array $changes): array
    {
        return array_map(static fn (array $pair): array => [self::plain($pair[0]), self::plain($pair[1])], $changes);
    }

    /** «Козацька 19, кв. 50 (220050)» — the address and the особовий рахунок people search by. */
    private static function place(?Account $account): string
    {
        if ($account === null) {
            return 'без об’єкта';
        }

        try {
            return sprintf('%s (%s)', $account->getStreetPlaceLabel(), $account->getAccountNumber());
        } catch (\Throwable) {
            return 'об’єкт #' . $account->getId();
        }
    }

    private static function money(mixed $value): string
    {
        return number_format((float)$value, 2, '.', ' ');
    }

    private static function phones(mixed $value): string
    {
        return is_array($value) && $value !== [] ? implode(', ', array_map('strval', $value)) : '—';
    }
}
