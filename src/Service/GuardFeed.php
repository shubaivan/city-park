<?php

namespace App\Service;

use App\Entity\GateEvent;
use App\Entity\QrScan;

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

        return [
            'key' => 'e' . $event->getId(),
            'type' => $event->isEntry() ? self::TYPE_IN : self::TYPE_OUT,
            'at' => $event->getCreatedAt(),
            'who' => $event->getPersonLabel() !== '' ? $event->getPersonLabel() : $event->getPlaceLabel(),
            'place' => $event->getPersonLabel() !== '' ? $event->getPlaceLabel() : '',
            'car' => $event->getCarLabel(),
            'kind' => $guest ? '👷 пропуск' : '🪪 мешканець',
            'pass_id' => $event->getGuestPassId(),
            'account_id' => $event->getSubjectAccount()?->getId(),
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
            'car' => null,
            'kind' => $guest ? '👷 пропуск' . ($scan->getGuestPassId() ? ' #' . $scan->getGuestPassId() : '') : '🪪 мешканець',
            'pass_id' => $scan->getGuestPassId(),
            'account_id' => $scan->getSubjectAccount()?->getId(),
            'by' => $scan->getScannedByLabel(),
            'by_guard' => $scan->isByGuard(),
            'problem' => self::problem($scan->getResult()),
            'replaces' => null,
        ];
    }

    /** Only a code that did not check out is worth the guard's attention. */
    public static function problem(string $result): ?string
    {
        return match ($result) {
            QrScan::RESULT_OK, QrScan::RESULT_NO_BOOKING => null,
            QrScan::RESULT_NOT_ACTIVE => '⚪️ пропуск не активний',
            QrScan::RESULT_REVOKED => '🗑 пропуск скасований',
            QrScan::RESULT_UNKNOWN => '⚠️ код не розпізнано',
            default => '⚠️ ' . $result,
        };
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
