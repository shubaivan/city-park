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
 * Silent when `RESIDENT_CHAT_TOPIC_POWER` is unset — no General fallback, the services
 * board's call — and never fatal.
 */
class PowerScheduleAnnouncer
{
    private const TZ = 'Europe/Kyiv';

    private const QUIET_FROM = 22;
    private const QUIET_UNTIL = 8;

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

        $houses = $this->queues->known();

        if ($houses === []) {
            // First run, before the daily queue check ever has: ask now rather than post
            // nothing — or, worse, a queue somebody assumed.
            $this->refreshQueues($dryRun);
            $houses = $this->queues->known();
        }

        if ($houses === []) {
            return ['Черкасиобленерго не назвало черги для жодного будинку — не публікую.'];
        }

        $html = $this->httpClient->request('GET', PowerChannelParser::CHANNEL_URL, [
            'timeout' => 15,
            'headers' => ['User-Agent' => 'Mozilla/5.0 (city-park bot)'],
        ])->getContent();

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $today = $now->format('Y-m-d');

        // The latest post for each day wins: «Оновлення графіка … на 28 жовтня».
        $byDay = [];
        foreach (PowerChannelParser::parsePage($html, $now) as $schedule) {
            $byDay[$schedule['date']] = $schedule;
        }

        $done = [];

        foreach ($byDay as $date => $schedule) {
            if ($date < $today) {
                continue;
            }

            $groups = self::groups($houses, $schedule['queues']);

            if ($groups === []) {
                $this->logger->warning('power: our queues are missing from the channel post', [
                    'post' => $schedule['post'],
                    'queues' => array_values(array_unique($houses)),
                ]);
                continue;
            }

            $day = new \DateTimeImmutable($date, new \DateTimeZone(self::TZ));
            $signature = json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $existing = $this->posts->forDay($day);

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
