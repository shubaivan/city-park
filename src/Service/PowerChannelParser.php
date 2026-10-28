<?php

namespace App\Service;

/**
 * Reads the hourly-outage schedule out of Черкасиобленерго's own Telegram channel.
 *
 * **The channel is the source of the hours, not the cabinet API.** The company posts one
 * message per schedule with a line for every one of the twelve sub-queues —
 * «3.1 12:00 - 14:00, 17:00 - 19:00» — and edits it, or posts «Оновлення графіка … на 28
 * жовтня», when Укренерго changes the plan during the day. Every «черга 3.1» bot in the
 * region is a parser of exactly this post. The cabinet API knows which queue an address
 * is in (see {@see PowerQueueResolver}) but its per-address list of windows does not
 * carry our street at all — the whole of October without a single «Козацька» in 31 000
 * rows — so it cannot be what tells the house when the light goes.
 *
 * The public web view, `t.me/s/<channel>`, needs no account and no token. It shows the
 * last twenty-odd posts, already edited in place, which is all this needs.
 *
 * Static and network-free on purpose: the format is the thing most likely to change
 * under us, and the test has to be able to feed it a post.
 */
class PowerChannelParser
{
    public const CHANNEL_URL = 'https://t.me/s/pat_cherkasyoblenergo';

    private const MONTHS = [
        'січня' => 1, 'лютого' => 2, 'березня' => 3, 'квітня' => 4, 'травня' => 5, 'червня' => 6,
        'липня' => 7, 'серпня' => 8, 'вересня' => 9, 'жовтня' => 10, 'листопада' => 11, 'грудня' => 12,
    ];

    /**
     * Fewer queue lines than this and it is not a schedule but a post that happens to
     * mention «3.1» — a news item, a rule explained. A real one lists all twelve.
     */
    private const MIN_QUEUE_LINES = 6;

    /**
     * Every schedule post on the page, oldest first.
     *
     * @return list<array{post: int, date: string, queues: array<string, list<array{int, int}>>}>
     */
    public static function parsePage(string $html, \DateTimeImmutable $now): array
    {
        $found = [];

        $chunks = preg_split('/(?=<div class="tgme_widget_message_wrap)/', $html) ?: [];

        foreach ($chunks as $chunk) {
            if (!preg_match('/data-post="[^"\/]+\/(\d+)"/', $chunk, $post)) {
                continue;
            }

            if (!preg_match('/<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)<\/div>/s', $chunk, $body)) {
                continue;
            }

            $text = html_entity_decode(
                strip_tags((string)preg_replace('/<br\s*\/?>/i', "\n", $body[1])),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );

            $schedule = self::parsePost($text, $now);

            if ($schedule !== null) {
                $found[] = ['post' => (int)$post[1]] + $schedule;
            }
        }

        usort($found, static fn (array $a, array $b) => $a['post'] <=> $b['post']);

        return $found;
    }

    /**
     * One post, or null when it is not a schedule.
     *
     * @return array{date: string, queues: array<string, list<array{int, int}>>}|null
     */
    public static function parsePost(string $text, \DateTimeImmutable $now): ?array
    {
        $date = self::date($text, $now);

        if ($date === null) {
            return null;
        }

        $queues = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (!preg_match('/^\s*(\d)\.(\d)\s+(.*)$/u', $line, $m)) {
                continue;
            }

            $queues[$m[1] . '.' . $m[2]] = self::intervals($m[3]);
        }

        if (count($queues) < self::MIN_QUEUE_LINES) {
            return null;
        }

        return ['date' => $date, 'queues' => $queues];
    }

    /**
     * «12:00 - 14:00, 17:00 - 19:00» → [[720, 840], [1020, 1140]], in minutes from
     * midnight, sorted and with touching windows merged — «10:00 - 12:00, 12:00 - 14:00»
     * is one outage, and announcing «увімкнуть о 12:00» between them would be untrue.
     * A line with no time on it («3.1 відключень не заплановано») is an empty list.
     *
     * @return list<array{int, int}>
     */
    public static function intervals(string $line): array
    {
        preg_match_all('/(\d{1,2})[:.](\d{2})\s*[-–—]\s*(\d{1,2})[:.](\d{2})/u', $line, $all, PREG_SET_ORDER);

        $list = [];

        foreach ($all as $m) {
            $from = (int)$m[1] * 60 + (int)$m[2];
            $to = (int)$m[3] * 60 + (int)$m[4];

            if ($from >= 0 && $to <= 1440 && $to > $from) {
                $list[] = [$from, $to];
            }
        }

        usort($list, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($list as [$from, $to]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
                continue;
            }

            $merged[] = [$from, $to];
        }

        return $merged;
    }

    /**
     * The day the schedule is *for* — «… на 28 жовтня», «28 жовтня з 06:00 до 24:00 …» —
     * which is not the day it was posted: tomorrow's plan arrives in the evening.
     *
     * The year is not in the text. The nearest 28 October to today is the right one:
     * a schedule is never more than a day or two ahead, and a post from late December
     * read on 1 January must stay last year's rather than become next December's.
     */
    private static function date(string $text, \DateTimeImmutable $now): ?string
    {
        if (!preg_match('/(\d{1,2})\s+(' . implode('|', array_keys(self::MONTHS)) . ')/u', mb_strtolower($text), $m)) {
            return null;
        }

        $day = (int)$m[1];
        $month = self::MONTHS[$m[2]];
        $best = null;

        foreach ([-1, 0, 1] as $shift) {
            $year = (int)$now->format('Y') + $shift;

            if (!checkdate($month, $day, $year)) {
                continue;
            }

            $candidate = $now->setDate($year, $month, $day)->setTime(0, 0);
            $distance = abs($candidate->getTimestamp() - $now->getTimestamp());

            if ($best === null || $distance < $best[0]) {
                $best = [$distance, $candidate];
            }
        }

        return $best === null ? null : $best[1]->format('Y-m-d');
    }
}
