<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * The month calendar on /admin/scans (Иван, 21.11.2026): where in time to look. Each day
 * says how many lines the feed has on it, coloured by how busy it was, and leads to that
 * day in the feed.
 *
 * The count is the feed's own: an entry / exit press, plus a scan that got no press —
 * a scan with its press is one line in the feed (GuardFeed::merge()), so it is one here.
 * One GROUP BY over both tables per month, whatever the month holds.
 */
class GuardCalendar
{
    private const MONTHS = [1 => 'Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень', 'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string, array{in: int, out: int, scans: int}> by `Y-m-d`, busy days only
     */
    public function counts(\DateTimeInterface $monthStart, ?int $passId = null, ?int $personId = null): array
    {
        $from = self::monthStart($monthStart);
        $to = (clone $from)->modify('+1 month');
        $pass = $passId !== null ? ' AND guest_pass_id = :pass' : '';
        // `?person=` (22.11.2026): a press counts when the scan under it was that person's code.
        $eventPerson = $personId !== null ? ' AND qr_scan_id IN (SELECT id FROM qr_scan WHERE subject_user_id = :person)' : '';
        $scanPerson = $personId !== null ? ' AND q.subject_user_id = :person' : '';

        $rows = $this->db->fetchAllAssociative(
            "SELECT d, SUM(i) AS i, SUM(o) AS o, SUM(s) AS s FROM (
                SELECT CAST(created_at AS DATE) AS d,
                       CASE WHEN direction = 'in' THEN 1 ELSE 0 END AS i,
                       CASE WHEN direction = 'in' THEN 0 ELSE 1 END AS o,
                       0 AS s
                  FROM gate_event
                 WHERE created_at >= :from AND created_at < :to{$pass}{$eventPerson}
                UNION ALL
                SELECT CAST(q.created_at AS DATE), 0, 0, 1
                  FROM qr_scan q
                 WHERE q.created_at >= :from AND q.created_at < :to" . str_replace('guest_pass_id', 'q.guest_pass_id', $pass) . $scanPerson . "
                   AND NOT EXISTS (SELECT 1 FROM gate_event g WHERE g.qr_scan_id = q.id)
            ) t GROUP BY d",
            array_filter([
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'pass' => $passId,
                'person' => $personId,
            ], static fn ($v) => $v !== null),
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[substr((string)$row['d'], 0, 10)] = ['in' => (int)$row['i'], 'out' => (int)$row['o'], 'scans' => (int)$row['s']];
        }

        return $counts;
    }

    /** The 1st of the month at 00:00. */
    public static function monthStart(\DateTimeInterface $day): \DateTime
    {
        return new \DateTime($day->format('Y-m-01') . ' 00:00:00');
    }

    /**
     * `?month=2026-11` → that month; anything else, or a month that has not started, → $default.
     */
    public static function parseMonth(?string $month, \DateTimeInterface $default, \DateTimeInterface $now): \DateTime
    {
        if ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $parsed = new \DateTime($month . '-01 00:00:00');

            if ($parsed <= self::monthStart($now)) {
                return $parsed;
            }
        }

        return self::monthStart($default);
    }

    /** «Листопад 2026» */
    public static function monthLabel(\DateTimeInterface $monthStart): string
    {
        return self::MONTHS[(int)$monthStart->format('n')] . ' ' . $monthStart->format('Y');
    }

    /**
     * The month as weeks Monday → Sunday; days outside the month are null.
     *
     * `level` 0–4 is how busy the day was against the month's busiest (0 = nothing).
     *
     * @param array<string, array{in: int, out: int, scans: int}> $counts
     *
     * @return list<array{monday: string, days: list<array{date: string, day: int, total: int, level: int, today: bool, future: bool}|null>}>
     */
    public static function grid(\DateTimeInterface $monthStart, array $counts, \DateTimeInterface $now): array
    {
        $first = self::monthStart($monthStart);
        $month = $first->format('Y-m');
        $today = $now->format('Y-m-d');
        $max = 0;

        foreach ($counts as $c) {
            $max = max($max, $c['in'] + $c['out'] + $c['scans']);
        }

        $weeks = [];
        $monday = GuardFeed::weekStart($first);

        while ($monday->format('Y-m') <= $month) {
            $days = [];

            for ($i = 0; $i < 7; $i++) {
                $day = (clone $monday)->modify("+{$i} days");
                $date = $day->format('Y-m-d');

                if ($day->format('Y-m') !== $month) {
                    $days[] = null;
                    continue;
                }

                $c = $counts[$date] ?? ['in' => 0, 'out' => 0, 'scans' => 0];
                $total = $c['in'] + $c['out'] + $c['scans'];

                $days[] = [
                    'date' => $date,
                    'day' => (int)$day->format('j'),
                    'total' => $total,
                    'level' => $total === 0 ? 0 : max(1, (int)ceil(4 * $total / $max)),
                    'today' => $date === $today,
                    'future' => $date > $today,
                ];
            }

            $weeks[] = ['monday' => $monday->format('Y-m-d'), 'days' => $days];
            $monday = (clone $monday)->modify('+7 days');
        }

        return $weeks;
    }
}
