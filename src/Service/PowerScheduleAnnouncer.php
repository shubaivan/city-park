<?php

namespace App\Service;

use App\Entity\PowerQueue;
use App\Entity\PowerSchedulePost;
use App\Repository\PowerQueueRepository;
use App\Repository\PowerSchedulePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * «💡 Світло» — the day's outage schedule for our buildings, in its own topic of the
 * residents' chat.
 *
 * Two sources, because neither answers both questions: Черкасиобленерго's channel says
 * *when* each queue is off ({@see PowerChannelParser}), and its cabinet API says *which*
 * queue each building is in ({@see PowerQueueResolver}). The second is what lets this say
 * «3.1» without anybody having typed it — and notice the day the company moves a building.
 *
 * **One post per day, always the current one.** A change of plan posts the new version
 * with «🔄 Графік оновлено» (a notification is the point: an edit is silent, and the
 * person who read the morning version is the one who needs to know) and deletes the old
 * one, which Telegram allows for 48 hours — a schedule is never for further ahead than
 * tomorrow. Where the delete is refused the old caption is struck through instead,
 * the same fallback as the services board.
 *
 * Silent at night (22:00–08:00 Kyiv): tomorrow's plan routinely arrives at 23:00, and a
 * buzz for 450 people at that hour is worse than reading it with the morning coffee.
 *
 * **Reminders go into the same topic, never to anybody's DMs** (Иван, 28.10.2026): fifteen
 * minutes before an outage («⚠️ Через 15 хвилин вимкнуть світло»), and fifteen minutes
 * before the schedule says it comes back, with the next one. **They stay, one after
 * another** (Иван, 29.10.2026): they were deleted by the next one for a day, and a topic
 * that rewrites itself reads as the bot losing messages — the day's run of warnings is
 * what somebody scrolls back through.
 * They ring by day: a warning nobody notices warns nobody, and a reader who does not want
 * it mutes the topic, which Telegram lets them do topic by topic.
 *
 * Silent when `RESIDENT_CHAT_TOPIC_POWER` is unset — no General fallback, the services
 * board's call — and never fatal.
 *
 * The same post goes to every chat in {@see PowerTargets}, each with its own queue,
 * its own post per day and its own reminders. One chat's failure does not reach another.
 */
class PowerScheduleAnnouncer
{
    private const TZ = 'Europe/Kyiv';

    private const QUIET_FROM = 22;
    private const QUIET_UNTIL = 8;

    /**
     * How long before the light goes, and before it is due back, the reminder is posted
     * (Иван, 29.10.2026: 15, and before both — it used to be 10 before the outage and the
     * «увімкнули» line at the moment itself). The
     * window opens a minute earlier than that, because the cron runs on even minutes
     * and an outage on the hour would otherwise be warned about at :46 — fourteen
     * minutes before, under a text that says fifteen.
     */
    public const WARN_BEFORE_MIN = 15;

    private const SITE_URL = 'https://www.cherkasyoblenergo.com/off';

    public function __construct(
        private Nutgram $bot,
        private ResidentChatService $residentChat,
        private PowerTargets $targets,
        private HttpClientInterface $httpClient,
        private PowerQueueRepository $queues,
        private PowerSchedulePostRepository $posts,
        private PowerQueueResolver $resolver,
        private PowerScheduleImage $image,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {}

    /**
     * Read the channel and bring the topic up to date. Returns what it did, one line each.
     *
     * With $dryRun nothing is sent or stored; the pictures are written to var/ instead.
     *
     * @return list<string>
     */
    public function run(bool $dryRun = false): array
    {
        $residents = $this->targets->residents();
        $others = $this->targets->others();
        $residentsLive = $dryRun || $this->isLive($residents);

        if (!$residentsLive && $others === []) {
            return ['RESIDENT_CHAT_TOPIC_POWER не налаштовано — нічого не надсилаю.'];
        }

        $done = [];

        // Before the channel is read, so a channel that does not answer cannot cost the
        // house its «через 15 хвилин».
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));

        if ($residentsLive) {
            $done = $this->remind($now, $residents, $dryRun);
        }

        $ready = [];

        if ($residentsLive) {
            $houses = $this->queues->known();

            if ($houses === []) {
                // First run, before the daily queue check ever has: ask now rather than post
                // nothing — or, worse, a queue somebody assumed.
                $this->refreshTarget($residents, $dryRun);
                $houses = $this->queues->known();
            }

            if ($houses === []) {
                $done[] = 'Черкасиобленерго не назвало черги для жодного будинку — не публікую.';
            } else {
                $ready[] = [$residents, $houses];
            }
        }

        foreach ($others as $target) {
            try {
                array_push($done, ...$this->remind($now, $target, $dryRun));

                $houses = $this->queues->known($target->key);

                if ($houses === []) {
                    $this->refreshTarget($target, $dryRun);
                    $houses = $this->queues->known($target->key);
                }

                if ($houses === []) {
                    $done[] = sprintf('[%s] Черкасиобленерго не назвало чергу — не публікую.', $target->key);
                    continue;
                }

                $ready[] = [$target, $houses];
            } catch (\Throwable $e) {
                $this->failed($target, $e, $done);
            }
        }

        if ($ready === []) {
            return $done;
        }

        $html = $this->httpClient->request('GET', PowerChannelParser::CHANNEL_URL, [
            'timeout' => 15,
            'headers' => ['User-Agent' => 'Mozilla/5.0 (city-park bot)'],
        ])->getContent();

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));

        // Every post for a day, oldest first: «Оновлення графіка … на 28 жовтня» is laid
        // over what came before it, not taken on its own (see PowerChannelParser::merge).
        $byDay = [];
        foreach (PowerChannelParser::parsePage($html, $now) as $schedule) {
            $byDay[$schedule['date']][] = $schedule;
        }

        foreach ($ready as [$target, $houses]) {
            if ($target->isResidents()) {
                array_push($done, ...$this->publish($target, $houses, $byDay, $now, $dryRun));
                continue;
            }

            // Another chat's trouble is its own: the residents' post has already gone.
            try {
                array_push($done, ...$this->publish($target, $houses, $byDay, $now, $dryRun));
            } catch (\Throwable $e) {
                $this->failed($target, $e, $done);
            }
        }

        return $done;
    }

    /**
     * Bring one chat up to date with the days on the channel's page.
     *
     * @param array<string, string> $houses
     * @param array<string, list<array{post: int, at: ?\DateTimeImmutable, queues: array<string, list<array{int, int}>>}>> $byDay
     * @return list<string>
     */
    private function publish(PowerTarget $target, array $houses, array $byDay, \DateTimeImmutable $now, bool $dryRun): array
    {
        $done = [];
        $today = $now->format('Y-m-d');
        $tag = $target->isResidents() ? '' : sprintf('[%s] ', $target->key);

        foreach ($byDay as $date => $dayPosts) {
            if ($date < $today) {
                continue;
            }

            $day = new \DateTimeImmutable($date, new \DateTimeZone(self::TZ));
            $existing = $this->posts->forDay($day, $target->key);
            $schedule = ['post' => end($dayPosts)['post'], 'queues' => self::layer($day, $dayPosts, $existing)];

            $groups = self::groups($houses, $schedule['queues']);

            if ($groups === []) {
                $this->logger->warning('power: our queues are missing from the channel post', [
                    'post' => $schedule['post'],
                    'queues' => array_values(array_unique($houses)),
                    'target' => $target->key,
                ]);
                continue;
            }

            $signature = json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            // Same hours, different list of buildings (буд. 25 joining its queue): the post
            // already standing is still true for everybody it names, so it is left alone
            // and the next day's post carries the new list — a «Графік оновлено» over hours
            // that did not move would only tell 450 people to look again at nothing.
            if ($existing !== null && ($existing->getSignature() === $signature || self::sameHours($existing->getSignature(), $groups))) {
                if (!$dryRun) {
                    $existing->seen($schedule['post']);
                    $this->em->flush();
                }
                continue;
            }

            $updated = $existing !== null;
            $caption = self::caption($day, $groups, $updated, $now, $target->place);
            $png = $this->image->render(self::title($day), self::rows($groups, $target->place), 'Черкасиобленерго · t.me/pat_cherkasyoblenergo');

            if ($dryRun) {
                $file = $target->isResidents()
                    ? sprintf('%s/var/power-preview-%s.png', $this->projectDir, $date)
                    : sprintf('%s/var/power-preview-%s-%s.png', $this->projectDir, $target->key, $date);
                file_put_contents($file, $png);
                $done[] = sprintf("%s[DRY] %s → %s\n%s", $tag, $date, $file, $caption);
                continue;
            }

            $messageId = $this->send($target, $png, $caption, $now);

            if ($messageId === null) {
                $done[] = sprintf('%s%s: не вдалося надіслати', $tag, $date);
                continue;
            }

            if ($existing !== null) {
                $this->retire($target, $existing);
                $existing->replace($signature, $schedule['post'])->setMessageId($messageId);
            } else {
                $existing = (new PowerSchedulePost($day, $signature, $schedule['post'], $target->key))->setMessageId($messageId);
                $this->em->persist($existing);
            }

            $this->em->flush();
            $done[] = sprintf('%s%s: %s (повідомлення %d)', $tag, $date, $updated ? 'оновлено' : 'опубліковано', $messageId);
        }

        return $done;
    }

    /** @param list<string> $done */
    private function failed(PowerTarget $target, \Throwable $e, array &$done): void
    {
        $this->logger->error('power: a target chat failed', ['target' => $target->key, 'error' => $e->getMessage()]);
        $done[] = sprintf('[%s] помилка: %s', $target->key, $e->getMessage());
    }

    /** Whether posts for this chat can go out at all. */
    private function isLive(PowerTarget $target): bool
    {
        return $target->isResidents()
            ? $this->residentChat->isConfigured() && $target->topic !== null
            : $target->chatId !== '';
    }

    /**
     * A day's queues from all its posts on the page, oldest first, each laid over the
     * one before. What the topic already shows is the starting point, since the post an
     * update corrects may have scrolled off the channel's page by then.
     *
     * @param list<array{post: int, at: ?\DateTimeImmutable, queues: array<string, list<array{int, int}>>}> $dayPosts
     * @return array<string, list<array{int, int}>>
     */
    public static function layer(\DateTimeImmutable $day, array $dayPosts, ?PowerSchedulePost $shown): array
    {
        $queues = [];

        foreach (json_decode($shown?->getSignature() ?? '[]', true) ?: [] as $group) {
            $queues[(string)$group['queue']] = $group['outages'];
        }

        foreach ($dayPosts as $post) {
            $cutoff = $post['at'] === null ? 0 : intdiv($post['at']->getTimestamp() - $day->getTimestamp(), 60);
            $queues = PowerChannelParser::merge($queues, $post['queues'], $cutoff);
        }

        return $queues;
    }

    /**
     * Post whatever reminder is due now. The earlier ones stay in the topic.
     *
     * @return list<string>
     */
    private function remind(\DateTimeImmutable $now, PowerTarget $target, bool $dryRun): array
    {
        $rows = $this->posts->around($now, $target->key);
        $tag = $target->isResidents() ? '' : sprintf('[%s] ', $target->key);
        $days = [];

        foreach ($rows as $date => $row) {
            $days[$date] = json_decode($row->getSignature(), true) ?: [];
        }

        $done = [];

        foreach (self::dueReminders($days, $now) as $due) {
            $row = $rows[$due['date']];

            if ($row->wasReminded($due['key'])) {
                continue;
            }

            if ($dryRun) {
                $done[] = sprintf("%s[DRY] нагадування %s %s\n%s", $tag, $due['date'], $due['key'], $due['text']);
                continue;
            }

            $hour = (int)$now->format('G');

            try {
                $message = $this->bot->sendMessage(
                    text: $due['text'],
                    chat_id: (int)$target->chatId,
                    message_thread_id: $target->topic,
                    parse_mode: ParseMode::HTML,
                    disable_notification: $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL,
                );
            } catch (\Throwable $e) {
                $this->logger->error('power: reminder failed', ['key' => $due['key'], 'target' => $target->key, 'error' => $e->getMessage()]);
                continue;
            }

            $row->markReminded($due['key'], $message?->message_id);
            $this->em->flush();
            $done[] = sprintf('%sнагадування %s %s', $tag, $due['date'], $due['key']);
        }

        return $done;
    }

    /**
     * The reminders whose moment is now, from the schedules drawn for yesterday, today
     * and tomorrow.
     *
     * «Off» is due from fifteen minutes before the outage until it starts — a warning after
     * the light has gone is no warning. «On» is due in the same fifteen minutes before the moment the
     * schedule says it returns, and is skipped where the next day's outage simply
     * continues the same one past midnight.
     *
     * @param array<string, list<array{queue: string, houses: list<string>, outages: list<array{int, int}>}>> $days
     * @return list<array{date: string, key: string, text: string}>
     */
    public static function dueReminders(array $days, \DateTimeImmutable $now): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $due = [];

        foreach ($days as $date => $groups) {
            $midnight = new \DateTimeImmutable($date . ' 00:00', $tz);
            $next = (new \DateTimeImmutable($date, $tz))->modify('+1 day')->format('Y-m-d');
            $prev = (new \DateTimeImmutable($date, $tz))->modify('-1 day')->format('Y-m-d');
            $several = count($groups) > 1;

            foreach ($groups as $group) {
                $label = $several
                    ? sprintf("\n<i>Черга %s · %s</i>", $group['queue'], self::housesLabel($group['houses']))
                    : sprintf("\n<i>Черга %s</i>", $group['queue']);
                $tomorrow = self::queueOn($days[$next] ?? null, $group['queue']);
                $yesterday = self::queueOn($days[$prev] ?? null, $group['queue']);

                foreach ($group['outages'] as $i => [$from, $to]) {
                    $start = $midnight->modify(sprintf('+%d minutes', $from));
                    $end = $midnight->modify(sprintf('+%d minutes', $to));

                    // 00:00 straight after a 22:00–24:00 is the same outage: it is already dark.
                    $alreadyOff = $from === 0 && ($yesterday !== null && $yesterday !== [] && end($yesterday)[1] === 1440);

                    if (!$alreadyOff && $now >= $start->modify(sprintf('-%d minutes', self::WARN_BEFORE_MIN + 1)) && $now < $start) {
                        $due[] = [
                            'date' => $date,
                            'key' => sprintf('off@%s@%d', $group['queue'], $from),
                            'text' => sprintf(
                                '⚠️ Через %d хвилин вимкнуть світло — о %s, до %s.%s',
                                self::WARN_BEFORE_MIN,
                                PowerScheduleImage::clock($from),
                                PowerScheduleImage::clock($to),
                                $label,
                            ),
                        ];
                    }

                    $continues = $to === 1440 && ($tomorrow[0][0] ?? null) === 0;

                    if (!$continues && $now >= $end->modify(sprintf('-%d minutes', self::WARN_BEFORE_MIN + 1)) && $now < $end) {
                        $later = $group['outages'][$i + 1] ?? null;
                        $then = match (true) {
                            $later !== null => sprintf('Наступне відключення — о %s.', PowerScheduleImage::clock($later[0])),
                            $tomorrow !== null && $tomorrow !== [] => sprintf('Наступне відключення — завтра о %s.', PowerScheduleImage::clock($tomorrow[0][0])),
                            $tomorrow === [] => 'Ні сьогодні, ні завтра відключень більше не заплановано.',
                            $to === 1440 => 'Графіка на сьогодні ще немає — щойно обленерго його опублікує, він буде тут.',
                            default => 'Сьогодні відключень більше не заплановано.',
                        };

                        $due[] = [
                            'date' => $date,
                            'key' => sprintf('on@%s@%d', $group['queue'], $to),
                            'text' => sprintf(
                                "🟢 Через %d хвилин, о %s, за графіком мають увімкнути світло.\n%s%s",
                                self::WARN_BEFORE_MIN,
                                PowerScheduleImage::clock($to),
                                $then,
                                $label,
                            ),
                        ];
                    }
                }
            }
        }

        return $due;
    }

    /**
     * That queue's windows on a day, or null when that day's schedule is not known yet.
     *
     * @param list<array{queue: string, houses: list<string>, outages: list<array{int, int}>}>|null $groups
     * @return list<array{int, int}>|null
     */
    private static function queueOn(?array $groups, string $queue): ?array
    {
        foreach ($groups ?? [] as $group) {
            if ($group['queue'] === $queue) {
                return $group['outages'];
            }
        }

        return null;
    }

    /**
     * Ask Черкасиобленерго which queue each building is in and store it. A building
     * whose queue moved is said out loud in the topic, once.
     *
     * @return array<string, array{was: ?string, now: ?string}> building → change, for every building
     */
    public function refreshQueues(bool $dryRun = false): array
    {
        $report = $this->refreshTarget($this->targets->residents(), $dryRun);

        foreach ($this->targets->others() as $target) {
            try {
                $report += $this->refreshTarget($target, $dryRun);
            } catch (\Throwable $e) {
                $this->logger->error('power: queue check failed', ['target' => $target->key, 'error' => $e->getMessage()]);
            }
        }

        return $report;
    }

    /**
     * @return array<string, array{was: ?string, now: ?string}>
     */
    private function refreshTarget(PowerTarget $target, bool $dryRun): array
    {
        $answers = $this->resolver->resolve($target->street, $target->houses);
        $rows = $this->queues->byHouse($target->key);
        $report = [];
        $moved = [];

        foreach ($answers as $house => $queue) {
            $row = $rows[$house] ?? null;
            $was = $row?->getQueue();
            $report[$target->reportKey((string)$house)] = ['was' => $was, 'now' => $queue];

            // An empty answer keeps what we knew: the company not naming a queue today is
            // not the company saying the building has none.
            if ($queue === null || $dryRun) {
                continue;
            }

            if ($row === null) {
                $row = new PowerQueue((string)$house, $target->key);
                $this->em->persist($row);
            }

            if ($row->record($queue) && $was !== null) {
                $moved[$house] = [$was, $queue];
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        if ($moved !== []) {
            $this->logger->warning('power: a building changed its outage queue', ['moved' => $moved, 'target' => $target->key]);
            $this->announceMove($target, $moved);
        }

        return $report;
    }

    /**
     * Building → queue folded into one entry per queue, in queue order, with that
     * queue's windows from the post. A queue the post does not list is left out.
     *
     * @param array<string, string> $houses
     * @param array<string, list<array{int, int}>> $queues
     * @return list<array{queue: string, houses: list<string>, outages: list<array{int, int}>}>
     */
    public static function groups(array $houses, array $queues): array
    {
        $byQueue = [];

        foreach ($houses as $house => $queue) {
            $byQueue[$queue][] = (string)$house;
        }

        ksort($byQueue, SORT_NATURAL);
        $groups = [];

        foreach ($byQueue as $queue => $list) {
            if (!array_key_exists((string)$queue, $queues)) {
                continue;
            }

            sort($list, SORT_NATURAL);
            $groups[] = ['queue' => (string)$queue, 'houses' => $list, 'outages' => $queues[(string)$queue]];
        }

        return $groups;
    }

    /**
     * Whether a stored signature and these groups give every queue the same outages.
     *
     * @param list<array{queue: string, houses: list<string>, outages: list<array{int, int}>}> $groups
     */
    public static function sameHours(string $signature, array $groups): bool
    {
        $hours = static function (array $groups): array {
            $map = [];
            foreach ($groups as $g) {
                $map[(string)($g['queue'] ?? '')] = $g['outages'] ?? null;
            }
            ksort($map, SORT_NATURAL);

            return $map;
        };

        $stored = json_decode($signature, true);

        return is_array($stored) && $hours($stored) === $hours($groups);
    }

    /**
     * The text under the picture: when it goes and when it comes back, in that order,
     * because «о котрій вимкнуть» is the question and the windows are the answer to a
     * different one.
     *
     * @param list<array{queue: string, houses: list<string>, outages: list<array{int, int}>}> $groups
     */
    public static function caption(\DateTimeImmutable $day, array $groups, bool $updated, \DateTimeImmutable $now, ?string $place = null): string
    {
        $lines = [];

        if ($updated) {
            $lines[] = sprintf('🔄 <b>Графік оновлено о %s</b>', $now->format('H:i'));
        }

        $lines[] = '💡 <b>' . self::title($day) . '</b>';

        foreach ($groups as $group) {
            $lines[] = '';
            $lines[] = sprintf('Черга <b>%s</b> · %s', $group['queue'], $place ?? self::housesLabel($group['houses']));

            if ($group['outages'] === []) {
                $lines[] = '✅ Відключень не заплановано.';
                continue;
            }

            foreach ($group['outages'] as [$from, $to]) {
                $lines[] = sprintf('🔴 %s — вимкнуть', PowerScheduleImage::clock($from));
                $lines[] = sprintf('🟢 %s — увімкнуть', PowerScheduleImage::clock($to));
            }

            $off = array_sum(array_map(static fn (array $w) => $w[1] - $w[0], $group['outages']));
            $lines[] = sprintf('Без світла: %s', self::duration($off));
        }

        $lines[] = '';
        $lines[] = '<i>За даними Черкасиобленерго. Якщо графік змінять, тут з\'явиться оновлений.</i>';

        return implode("\n", $lines);
    }

    /** «Середа, 29 жовтня». */
    public static function title(\DateTimeImmutable $day): string
    {
        static $genitive = [
            1 => 'січня', 2 => 'лютого', 3 => 'березня', 4 => 'квітня', 5 => 'травня', 6 => 'червня',
            7 => 'липня', 8 => 'серпня', 9 => 'вересня', 10 => 'жовтня', 11 => 'листопада', 12 => 'грудня',
        ];

        return sprintf(
            'Світло · %s, %d %s',
            mb_strtolower(UkDateFormatter::dayName((int)$day->format('N'))),
            (int)$day->format('j'),
            $genitive[(int)$day->format('n')],
        );
    }

    /** @param list<string> $houses */
    public static function housesLabel(array $houses): string
    {
        return 'буд. ' . implode(', ', $houses);
    }

    /** 270 → «4 год 30 хв». */
    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h > 0 ? $h . ' год' : '') . ($m > 0 ? ' ' . $m . ' хв' : '')) ?: '0 хв';
    }

    /**
     * @param list<array{queue: string, houses: list<string>, outages: list<array{int, int}>}> $groups
     * @return list<array{label: string, outages: list<array{int, int}>}>
     */
    private static function rows(array $groups, ?string $place = null): array
    {
        return array_map(static fn (array $g) => [
            'label' => sprintf('Черга %s · %s', $g['queue'], $place ?? self::housesLabel($g['houses'])),
            'outages' => $g['outages'],
        ], $groups);
    }

    private function send(PowerTarget $target, string $png, string $caption, \DateTimeImmutable $now): ?int
    {
        $hour = (int)$now->format('G');
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $png);
        rewind($stream);

        try {
            $message = $this->bot->sendPhoto(
                photo: InputFile::make($stream, 'svitlo.png'),
                chat_id: (int)$target->chatId,
                message_thread_id: $target->topic,
                caption: $caption,
                parse_mode: ParseMode::HTML,
                disable_notification: $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('🔎 Черга за адресою — сайт обленерго', url: self::SITE_URL),
                ),
            );

            return $message?->message_id;
        } catch (\Throwable $e) {
            $this->logger->error('power: schedule post failed', ['target' => $target->key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The superseded post goes; where Telegram refuses, it is marked as old instead. */
    private function retire(PowerTarget $target, PowerSchedulePost $old): void
    {
        $messageId = $old->getMessageId();

        if ($messageId === null) {
            return;
        }

        $chatId = (int)$target->chatId;

        try {
            $this->bot->deleteMessage($chatId, $messageId);

            return;
        } catch (\Throwable $e) {
            $this->logger->info('power: could not delete the old schedule, marking it', ['error' => $e->getMessage()]);
        }

        try {
            $this->bot->editMessageCaption(
                chat_id: $chatId,
                message_id: $messageId,
                caption: '⛔ <s>' . self::title($old->getDay()) . '</s> — графік змінили, актуальний нижче.',
                parse_mode: ParseMode::HTML,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('power: could not mark the old schedule', ['error' => $e->getMessage()]);
        }
    }

    /** @param array<string, array{string, string}> $moved */
    private function announceMove(PowerTarget $target, array $moved): void
    {
        if (!$this->isLive($target)) {
            return;
        }

        $lines = ['⚠️ <b>Черкасиобленерго змінило чергу відключень</b>', ''];
        foreach ($moved as $house => [$was, $now]) {
            $lines[] = sprintf('%s: було %s → тепер <b>%s</b>', $target->place ?? 'буд. ' . $house, $was, $now);
        }
        $lines[] = '';
        $lines[] = 'Графіки тут далі йтимуть уже за новою чергою.';

        try {
            $this->bot->sendMessage(
                text: implode("\n", $lines),
                chat_id: (int)$target->chatId,
                message_thread_id: $target->topic,
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('🔎 Перевірити на сайті обленерго', url: self::SITE_URL),
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->error('power: queue change post failed', ['error' => $e->getMessage()]);
        }
    }
}
