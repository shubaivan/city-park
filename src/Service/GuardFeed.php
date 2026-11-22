<?php

namespace App\Service;

use App\Entity\GateEvent;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Entity\TelegramUser;

/**
 * The guard post's feed on /admin/scans (Иван, 20.11.2026): one list, newest first,
 * grouped by day, one week at a time.
 *
 * Three kinds of line, interleaved by time:
 *
 * - **in** — «🚗 В'їзд» the guard pressed under a valid scan (green arrow);
 * - **out** — «🚙 Виїзд» (red arrow);
 * - **scan** — a QR scan nobody marked as either (a neutral mark): a resident checked at
 *   the door, a pass shown, a code that did not check out.
 *
 * A scan that got its in/out press is drawn once, as the press: the press already names
 * the same person, flat and car, and two lines for one car read as two cars. When the
 * press arrives after the scan was already on the screen, the press says which line it
 * `replaces`, and the page swaps them.
 *
 * Whether the resident had the альтанка booked («без броні») is not shown: it says nothing
 * to somebody standing at the gate (Иван, 20.11.2026). What is shown is a code that did
 * NOT check out — a revoked or not yet active pass, an unrecognised code.
 */
final class GuardFeed
{
    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';
    public const TYPE_SCAN = 'scan';

    /** Where a pass's own feed (`?pass=`) starts looking: before the first scan the house ever logged. */
    public const SINCE = '2026-01-01 00:00:00';

    /** How many of a flat's linked people a resident line names — the bot's answer names four, a line has room for fewer. */
    public const MAX_CONTACTS = 3;

    private const DAYS = [1 => 'Понеділок', 2 => 'Вівторок', 3 => 'Середа', 4 => 'Четвер', 5 => "П'ятниця", 6 => 'Субота', 7 => 'Неділя'];

    /** Monday 00:00 of the week that holds $day. */
    public static function weekStart(\DateTimeInterface $day): \DateTime
    {
        $monday = new \DateTime($day->format('Y-m-d') . ' 00:00:00');
        $monday->modify('-' . ((int)$monday->format('N') - 1) . ' days');

        return $monday;
    }

    /**
     * `?week=2026-11-16` → that week's Monday; anything else, or a week that has not
     * started yet, → the current one.
     */
    public static function parseWeek(?string $week, \DateTimeInterface $now): \DateTime
    {
        $current = self::weekStart($now);

        if ($week === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
            return $current;
        }

        $parsed = \DateTime::createFromFormat('!Y-m-d', $week);

        if ($parsed === false || $parsed->format('Y-m-d') !== $week) {
            return $current;
        }

        $start = self::weekStart($parsed);

        return $start > $current ? $current : $start;
    }

    public static function dayLabel(\DateTimeInterface $day): string
    {
        return self::DAYS[(int)$day->format('N')] . ', ' . $day->format('d.m');
    }

    /** «16.11 – 22.11.2026» */
    public static function weekLabel(\DateTimeInterface $monday): string
    {
        $sunday = (new \DateTime($monday->format('Y-m-d')))->modify('+6 days');

        return $monday->format('d.m') . ' – ' . $sunday->format('d.m.Y');
    }

    /**
     * @param QrScan[] $scans
     * @param GateEvent[] $events
     *
     * @return list<array<string, mixed>> newest first
     */
    public static function merge(array $scans, array $events): array
    {
        $marked = [];
        $items = [];

        foreach ($events as $event) {
            $scanId = $event->getQrScan()?->getId();

            if ($scanId !== null) {
                $marked[$scanId] = true;
            }

            $items[] = self::fromEvent($event);
        }

        foreach ($scans as $scan) {
            if ($scan->getId() !== null && isset($marked[$scan->getId()])) {
                continue;
            }

            $items[] = self::fromScan($scan);
        }

        usort($items, static fn (array $a, array $b): int => [$b['at'], $b['key']] <=> [$a['at'], $a['key']]);

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items newest first
     *
     * @return list<array{date: string, label: string, items: list<array<string, mixed>>, in: int, out: int, scans: int}>
     */
    public static function byDay(array $items): array
    {
        $days = [];

        foreach ($items as $item) {
            $date = $item['at']->format('Y-m-d');
            $days[$date] ??= ['date' => $date, 'label' => self::dayLabel($item['at']), 'items' => [], 'in' => 0, 'out' => 0, 'scans' => 0];
            $days[$date]['items'][] = $item;
            $days[$date][match ($item['type']) {
                self::TYPE_IN => 'in',
                self::TYPE_OUT => 'out',
                default => 'scans',
            }]++;
        }

        return array_values($days);
    }

    /** @return array<string, mixed> */
    public static function fromEvent(GateEvent $event): array
    {
        $guest = $event->getKind() === QrScan::KIND_GUEST;
        $personLabel = (string)$event->getQrScan()?->getSubjectUserLabel();
        // A pass line and a personal-code line lead with the flat: the pass (purpose, car,
        // status) and the person get their own line under it (22.11.2026).
        $flatFirst = ($guest && $event->getGuestPassId() !== null) || $personLabel !== '' || $event->getPersonLabel() === '';

        return [
            'key' => 'e' . $event->getId(),
            'type' => $event->isEntry() ? self::TYPE_IN : self::TYPE_OUT,
            'at' => $event->getCreatedAt(),
            'who' => $flatFirst ? $event->getPlaceLabel() : $event->getPersonLabel(),
            'place' => $flatFirst ? '' : $event->getPlaceLabel(),
            'pass_label' => $guest ? (string)preg_replace('/^👷\s*/u', '', $event->getPersonLabel()) : '',
            'car' => $event->getCarLabel(),
            'kind' => $guest ? '👷 пропуск' . ($event->getGuestPassId() ? ' №' . $event->getGuestPassId() : '') : '🪪 мешканець',
            'guest' => $guest,
            'pass_id' => $event->getGuestPassId(),
            'account_id' => $event->getSubjectAccount()?->getId(),
            'person' => $event->getQrScan()?->getSubjectUser(),
            'person_id' => $event->getQrScan()?->getSubjectUser()?->getId(),
            'person_label' => (string)$event->getQrScan()?->getSubjectUserLabel(),
            'by' => $event->getGuardLabel() . ($event->getGate() ? ' · ' . $event->getGate() : ''),
            'by_guard' => true,
            'problem' => null,
            'replaces' => $event->getQrScan()?->getId() !== null ? 's' . $event->getQrScan()->getId() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function fromScan(QrScan $scan): array
    {
        $guest = $scan->getKind() === QrScan::KIND_GUEST;

        return [
            'key' => 's' . $scan->getId(),
            'type' => self::TYPE_SCAN,
            'at' => $scan->getCreatedAt(),
            'who' => $scan->getSubjectLabel(),
            'place' => '',
            'pass_label' => '',
            'car' => null,
            'kind' => $guest ? '👷 пропуск' . ($scan->getGuestPassId() ? ' №' . $scan->getGuestPassId() : '') : '🪪 мешканець',
            'guest' => $guest,
            'pass_id' => $scan->getGuestPassId(),
            'account_id' => $scan->getSubjectAccount()?->getId(),
            'person' => $scan->getSubjectUser(),
            'person_id' => $scan->getSubjectUser()?->getId(),
            'person_label' => $scan->getSubjectUserLabel(),
            'by' => $scan->getScannedByLabel(),
            'by_guard' => $scan->isByGuard(),
            'problem' => self::problem($scan->getResult()),
            'replaces' => null,
        ];
    }

    /**
     * The pass ids the lines name — every pass, revoked and expired ones included: the live
     * list above the feed holds only the live ones, and «хто видав» matters most for a pass
     * that is already gone.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<int>
     */
    public static function passIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item['guest'] && $item['pass_id'] !== null) {
                $ids[(int)$item['pass_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The flats whose resident QR the lines name.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<int>
     */
    public static function residentAccountIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (!$item['guest'] && $item['account_id'] !== null) {
                $ids[(int)$item['account_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Who to ring, beside every line (Иван, 21.11.2026 — «це важливо»): a pass line gets
     * the pass's issuer (`issuer`, null when unknown), a resident line the people linked to
     * the flat whose code it is (`contacts`, at most MAX_CONTACTS). Both are batch-loaded by
     * the caller, one query each, so a week of lines costs two queries, not one per line.
     *
     * @param list<array<string, mixed>> $items
     * @param GuestPass[] $passes any order; matched by id
     * @param TelegramUser[] $people any order; matched by their account's id
     *
     * @return list<array<string, mixed>>
     */
    public static function withContacts(array $items, array $passes, array $people, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $issuers = [];
        $byId = [];

        foreach ($passes as $pass) {
            $issuers[$pass->getId()] = $pass->getIssuedBy();
            $byId[$pass->getId()] = $pass;
        }

        $byAccount = [];

        foreach ($people as $person) {
            $accountId = $person->getAccount()?->getId();

            if ($accountId !== null && count($byAccount[$accountId] ?? []) < self::MAX_CONTACTS) {
                $byAccount[$accountId][] = $person;
            }
        }

        foreach ($items as &$item) {
            $pass = $item['guest'] && $item['pass_id'] !== null ? ($byId[(int)$item['pass_id']] ?? null) : null;
            $item['issuer'] = $item['guest'] && $item['pass_id'] !== null ? ($issuers[(int)$item['pass_id']] ?? null) : null;
            $item['pass_info'] = $pass !== null ? self::passSummary($pass, $now) : null;
            $item['contacts'] = !$item['guest'] && $item['account_id'] !== null ? ($byAccount[(int)$item['account_id']] ?? []) : [];

            // A personal code (22.11.2026): its holder leads the contacts, the rest of the
            // flat follows; the holder is no longer on the flat → only the label is left.
            $person = $item['person'] ?? null;

            if (!$item['guest'] && $person instanceof TelegramUser) {
                $others = array_values(array_filter(
                    $item['contacts'],
                    static fn (TelegramUser $p): bool => $p !== $person && ($p->getId() === null || $p->getId() !== $person->getId()),
                ));
                $item['contacts'] = array_slice($others, 0, self::MAX_CONTACTS - 1);
            }
        }
        unset($item);

        return $items;
    }

    /** Only a code that did not check out is worth the guard's attention. */
    public static function problem(string $result): ?string
    {
        return match ($result) {
            QrScan::RESULT_OK, QrScan::RESULT_NO_BOOKING => null,
            QrScan::RESULT_NOT_ACTIVE => '⚪️ пропуск не активний',
            QrScan::RESULT_REVOKED => '🗑 пропуск скасований',
            QrScan::RESULT_UNKNOWN => '⚠️ код не розпізнано',
            QrScan::RESULT_PERSON_GONE => '⛔ код людини, яку відв\'язали від квартири',
            default => '⚠️ ' . $result,
        };
    }

    /**
     * What the guard must read off a pass line without clicking (Иван, 22.11.2026): which
     * pass, what for, the car, whether it is live right now and how often it was used.
     * A pass has no type or use limit of its own — its label is the purpose, typed by the
     * flat, and since 09.11 it leads with the car number when there is one.
     *
     * @return array{id:int, label:string, purpose:string, plate:?string, status:string, status_label:string, scans:int}
     */
    public static function passSummary(GuestPass $pass, \DateTimeInterface $now): array
    {
        [$plate, $purpose] = self::splitPlate($pass->getLabel());

        if ($pass->isRevoked()) {
            $status = 'revoked';
            $label = '🗑 скасований ' . $pass->getRevokedAt()?->format('d.m H:i');
        } elseif ($pass->isActiveAt($now)) {
            $status = 'active';
            $label = '✅ діє до ' . $pass->getActiveUntil()?->format('d.m H:i');
        } else {
            $status = 'inactive';
            $label = '⚪️ не активний' . ($pass->getActiveUntil() !== null ? ' · діяв до ' . $pass->getActiveUntil()->format('d.m H:i') : '');
        }

        return [
            'id' => (int)$pass->getId(),
            'label' => $pass->getLabel(),
            'purpose' => $purpose,
            'plate' => $plate,
            'status' => $status,
            'status_label' => trim($label),
            'scans' => $pass->getScans(),
        ];
    }

    /**
     * «AA1234BB доставка пральної машини» → ['AA 1234 BB', 'доставка пральної машини'].
     * Only the standard «AA 1234 AA» shape (spaces allowed, either alphabet), or a first
     * word PlateKey accepts as a plate; anything else stays the purpose, untouched.
     *
     * @return array{0:?string, 1:string}
     */
    public static function splitPlate(string $label): array
    {
        $label = trim($label);
        $L = '[A-ZАВСЕНІКМОРТХУ]';

        if (preg_match('/^(' . $L . '{2}\s?\d{4}\s?' . $L . '{2})(?=$|[\s,.;:—–-])[\s,.;:—–-]*(.*)$/iu', $label, $m) === 1) {
            $key = PlateKey::of($m[1]);

            if ($key !== '') {
                return [PlateKey::display($key), trim($m[2])];
            }
        }

        $words = preg_split('/[\s,;]+/u', $label, 2);
        $first = $words[0] ?? '';

        if ($first !== '' && preg_match('/\d/', $first) === 1 && preg_match('/\p{L}/u', $first) === 1) {
            $key = PlateKey::of($first);

            if ($key !== '') {
                return [PlateKey::display($key), (string)preg_replace('/^[\s,.;:—–-]+|[\s,.;:—–-]+$/u', '', (string)($words[1] ?? ''))];
            }
        }

        return [null, $label];
    }

    /**
     * The people whose personal code the lines name.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<int>
     */
    public static function personIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (($item['person_id'] ?? null) !== null) {
                $ids[(int)$item['person_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The newest ids on the screen — where the poll starts. Ids only grow, so the newest in
     * the current week are the newest anywhere.
     *
     * @param QrScan[] $scans
     * @param GateEvent[] $events
     *
     * @return array{scan: int, event: int}
     */
    public static function cursor(array $scans, array $events, int $scan = 0, int $event = 0): array
    {
        foreach ($scans as $s) {
            $scan = max($scan, (int)$s->getId());
        }

        foreach ($events as $e) {
            $event = max($event, (int)$e->getId());
        }

        return ['scan' => $scan, 'event' => $event];
    }
}
