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
 * **Reminders go into the same topic, never to anybody's DMs** (Иван, 28.10.2026): ten
 * minutes before an outage («⚠️ Через 15 хвилин вимкнуть світло»), and at the moment the
 * schedule says it comes back, with the next one. Each new reminder deletes the previous,
 * so the topic reads as the day's schedule plus one current line — not forty of them.
 * They ring by day: a warning nobody notices warns nobody, and a reader who does not want
 * it mutes the topic, which Telegram lets them do topic by topic.
 *
 * Silent when `RESIDENT_CHAT_TOPIC_POWER` is unset — no General fallback, the services
 * board's call — and never fatal.
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
        $topic = $this->residentChat->topic(ResidentChatService::TOPIC_POWER);

        if (!$dryRun && (!$this->residentChat->isConfigured() || $topic === null)) {
            return ['RESIDENT_CHAT_TOPIC_POWER не налаштовано — нічого не надсилаю.'];
        }

        // Before the channel is read, so a channel that does not answer cannot cost the
        // house its «через 15 хвилин».
        $done = $this->remind(new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)), $topic, $dryRun);

        $houses = $this->queues->known();

        if ($houses === []) {
            // First run, before the daily queue check ever has: ask now rather than post
            // nothing — or, worse, a queue somebody assumed.
            $this->refreshQueues($dryRun);
            $houses = $this->queues->known();
        }

        if ($houses === []) {
            return [...$done, 'Черкасиобленерго не назвало черги для жодного будинку — не публікую.'];
        }

        $html = $this->httpClient->request('GET', PowerChannelParser::CHANNEL_URL, [
            'timeout' => 15,
            'headers' => ['User-Agent' => 'Mozilla/5.0 (city-park bot)'],
        ])->getContent();

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $today = $now->format('Y-m-d');

        // Every post for a day, oldest first: «Оновлення графіка … на 28 жовтня» is laid
        // over what came before it, not taken on its own (see PowerChannelParser::merge).
        $byDay = [];
        foreach (PowerChannelParser::parsePage($html, $now) as $schedule) {
            $byDay[$schedule['date']][] = $schedule;
        }

        foreach ($byDay as $date => $dayPosts) {
            if ($date < $today) {
                continue;
            }

            $day = new \DateTimeImmutable($date, new \DateTimeZone(self::TZ));
            $existing = $this->posts->forDay($day);
            $schedule = ['post' => end($dayPosts)['post'], 'queues' => self::layer($day, $dayPosts, $existing)];

            $groups = self::groups($houses, $schedule['queues']);

            if ($groups === []) {
                $this->logger->warning('power: our queues are missing from the channel post', [
                    'post' => $schedule['post'],
                    'queues' => array_values(array_unique($houses)),
                ]);
                continue;
            }

            $signature = json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($existing !== null && $existing->getSignature() === $signature) {
                if (!$dryRun) {
                    $existing->seen($schedule['post']);
                    $this->em->flush();
                }
                continue;
            }

            $updated = $existing !== null;
            $caption = self::caption($day, $groups, $updated, $now);
            $png = $this->image->render(self::title($day), self::rows($groups), 'Черкасиобленерго · t.me/pat_cherkasyoblenergo');

            if ($dryRun) {
                $file = sprintf('%s/var/power-preview-%s.png', $this->projectDir, $date);
                file_put_contents($file, $png);
                $done[] = sprintf("[DRY] %s → %s\n%s", $date, $file, $caption);
                continue;
            }

            $messageId = $this->send($topic, $png, $caption, $now);

            if ($messageId === null) {
                $done[] = sprintf('%s: не вдалося надіслати', $date);
                continue;
            }

            if ($existing !== null) {
                $this->retire($existing);
                $existing->replace($signature, $schedule['post'])->setMessageId($messageId);
            } else {
                $existing = (new PowerSchedulePost($day, $signature, $schedule['post']))->setMessageId($messageId);
                $this->em->persist($existing);
            }

            $this->em->flush();
            $done[] = sprintf('%s: %s (повідомлення %d)', $date, $updated ? 'оновлено' : 'опубліковано', $messageId);
        }

        return $done;
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
     * Post whatever reminder is due now, and retire the one before it.
     *
     * @return list<string>
     */
    private function remind(\DateTimeImmutable $now, ?int $topic, bool $dryRun): array
    {
        $rows = $this->posts->around($now);
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

            if ($dryRun || $topic === null) {
                $done[] = sprintf("[DRY] нагадування %s %s\n%s", $due['date'], $due['key'], $due['text']);
                continue;
            }

            $hour = (int)$now->format('G');

            try {
                $message = $this->bot->sendMessage(
                    text: $due['text'],
                    chat_id: (int)$this->residentChat->chatId(),
                    message_thread_id: $topic,
                    parse_mode: ParseMode::HTML,
                    disable_notification: $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL,
                );
            } catch (\Throwable $e) {
                $this->logger->error('power: reminder failed', ['key' => $due['key'], 'error' => $e->getMessage()]);
                continue;
            }

            foreach ($rows as $other) {
                $old = $other->getReminderMessageId();

                if ($old !== null) {
                    try {
                        $this->bot->deleteMessage((int)$this->residentChat->chatId(), $old);
                    } catch (\Throwable $e) {
                        $this->logger->info('power: old reminder not deleted', ['error' => $e->getMessage()]);
                    }
                    $other->forgetReminderMessage();
                }
            }

            $row->markReminded($due['key'], $message?->message_id);
            $this->em->flush();
            $done[] = sprintf('нагадування %s %s', $due['date'], $due['key']);
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
        $answers = $this->resolver->resolve();
        $rows = $this->queues->byHouse();
        $report = [];
        $moved = [];

        foreach ($answers as $house => $queue) {
            $row = $rows[$house] ?? null;
            $was = $row?->getQueue();
            $report[$house] = ['was' => $was, 'now' => $queue];

            // An empty answer keeps what we knew: the company not naming a queue today is
            // not the company saying the building has none.
            if ($queue === null || $dryRun) {
                continue;
            }

            if ($row === null) {
                $row = new PowerQueue($house);
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
            $this->logger->warning('power: a building changed its outage queue', ['moved' => $moved]);
            $this->announceMove($moved);
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
     * The text under the picture: when it goes and when it comes back, in that order,
     * because «о котрій вимкнуть» is the question and the windows are the answer to a
     * different one.
     *
     * @param list<array{queue: string, houses: list<string>, outages: list<array{int, int}>}> $groups
     */
    public static function caption(\DateTimeImmutable $day, array $groups, bool $updated, \DateTimeImmutable $now): string
    {
        $lines = [];

        if ($updated) {
            $lines[] = sprintf('🔄 <b>Графік оновлено о %s</b>', $now->format('H:i'));
        }

        $lines[] = '💡 <b>' . self::title($day) . '</b>';

        foreach ($groups as $group) {
            $lines[] = '';
            $lines[] = sprintf('Черга <b>%s</b> · %s', $group['queue'], self::housesLabel($group['houses']));

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
    private static function rows(array $groups): array
    {
        return array_map(static fn (array $g) => [
            'label' => sprintf('Черга %s · %s', $g['queue'], self::housesLabel($g['houses'])),
            'outages' => $g['outages'],
        ], $groups);
    }

    private function send(int $topic, string $png, string $caption, \DateTimeImmutable $now): ?int
    {
        $hour = (int)$now->format('G');
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $png);
        rewind($stream);

        try {
            $message = $this->bot->sendPhoto(
                photo: InputFile::make($stream, 'svitlo.png'),
                chat_id: (int)$this->residentChat->chatId(),
                message_thread_id: $topic,
                caption: $caption,
                parse_mode: ParseMode::HTML,
                disable_notification: $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('🔎 Черга за адресою — сайт обленерго', url: self::SITE_URL),
                ),
            );

            return $message?->message_id;
        } catch (\Throwable $e) {
            $this->logger->error('power: schedule post failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** The superseded post goes; where Telegram refuses, it is marked as old instead. */
    private function retire(PowerSchedulePost $old): void
    {
        $messageId = $old->getMessageId();

        if ($messageId === null) {
            return;
        }

        $chatId = (int)$this->residentChat->chatId();

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
    private function announceMove(array $moved): void
    {
        $topic = $this->residentChat->topic(ResidentChatService::TOPIC_POWER);

        if (!$this->residentChat->isConfigured() || $topic === null) {
            return;
        }

        $lines = ['⚠️ <b>Черкасиобленерго змінило чергу відключень</b>', ''];
        foreach ($moved as $house => [$was, $now]) {
            $lines[] = sprintf('буд. %s: було %s → тепер <b>%s</b>', $house, $was, $now);
        }
        $lines[] = '';
        $lines[] = 'Графіки тут далі йтимуть уже за новою чергою.';

        try {
            $this->bot->sendMessage(
                text: implode("\n", $lines),
                chat_id: (int)$this->residentChat->chatId(),
                message_thread_id: $topic,
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
