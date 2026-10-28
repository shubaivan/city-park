<?php

namespace App\Tests\Service;

use App\Service\PowerChannelParser;
use App\Service\PowerQueueResolver;
use App\Service\PowerScheduleAnnouncer;
use App\Service\PowerScheduleImage;
use PHPUnit\Framework\TestCase;

/**
 * «💡 Світло»: the channel post is read right, our queue is the one the company names,
 * and what the house is told matches the post.
 *
 * The post below is Черкасиобленерго's own, 28.10.2026, verbatim but for the footer.
 */
class PowerScheduleRulesTest extends TestCase
{
    private const POST = <<<'TXT'
        Оновлення графіка погодинних відключень (ГПВ) на 28 жовтня

        Години відсутності електропостачання:

        1.1 10:00 - 12:00, 16:00 - 18:00, 22:00 - 24:00

        1.2 10:00 - 12:00, 16:00 - 18:00, 22:00 - 24:00

        2.1 10:00 - 13:00, 15:00 - 17:00, 20:00 - 22:00

        2.2 12:00 - 14:00, 16:00 - 18:00, 20:00 - 22:00

        3.1 12:00 - 14:00, 17:00 - 19:00

        3.2 12:00 - 14:00, 18:00 - 20:00

        4.1 13:00 - 16:00, 18:00 - 20:00

        4.2 13:00 - 15:00, 18:00 - 20:00, 22:00 - 24:00

        5.1 11:00 - 13:00, 16:00 - 18:00, 20:00 - 22:00

        5.2 14:00 - 16:00, 20:00 - 21:00

        6.1 14:00 - 16:00, 18:00 - 20:00

        6.2 09:00 - 11:00, 14:00 - 16:00, 19:00 - 21:00

        Свою чергу/підчергу можна дізнатися у чат-ботах, розділ "Відключення"-"Черги ГПВ/ГАВ"
        TXT;

    private static function now(string $at = '2026-10-28 09:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('Europe/Kyiv'));
    }

    public function testThePostIsReadQueueByQueue(): void
    {
        $schedule = PowerChannelParser::parsePost(self::POST, self::now());

        $this->assertNotNull($schedule);
        $this->assertSame('2026-10-28', $schedule['date']);
        $this->assertCount(12, $schedule['queues']);
        $this->assertSame([[720, 840], [1020, 1140]], $schedule['queues']['3.1']);
        $this->assertSame([[600, 720], [960, 1080], [1320, 1440]], $schedule['queues']['1.1']);
    }

    /** «з 06:00 до 24:00 будуть застосовані ГПВ» is the other shape the first post takes. */
    public function testTheAnnouncementShapeIsReadToo(): void
    {
        $text = "Через постійні ворожі обстріли … 28 жовтня з 06:00 до 24:00 у Черкаській області будуть застосовані графіки погодинних відключень (ГПВ).\n\n"
            . preg_replace('/^.*?(?=1\.1 )/s', '', self::POST);

        $schedule = PowerChannelParser::parsePost($text, self::now());

        $this->assertSame('2026-10-28', $schedule['date'] ?? null);
        $this->assertSame([[720, 840], [1020, 1140]], $schedule['queues']['3.1'] ?? null);
    }

    /** A news item that mentions a queue or two is not a schedule. */
    public function testAPostThatMerelyMentionsAQueueIsNotASchedule(): void
    {
        $this->assertNull(PowerChannelParser::parsePost(
            "28 жовтня бригади відновили живлення.\n3.1 та 3.2 — без змін.",
            self::now(),
        ));
    }

    /** Two touching windows are one outage: «увімкнуть о 12:00» between them is untrue. */
    public function testTouchingWindowsMerge(): void
    {
        $this->assertSame([[600, 840]], PowerChannelParser::intervals('10:00 - 12:00, 12:00 - 14:00'));
        $this->assertSame([[360, 450]], PowerChannelParser::intervals('06:00 – 07:30'));
        $this->assertSame([], PowerChannelParser::intervals('відключень не заплановано'));
    }

    /** The year is not in the post: the nearest date to today is the one meant. */
    public function testTheYearIsTheNearestOne(): void
    {
        $text = str_replace('28 жовтня', '31 грудня', self::POST);
        $this->assertSame('2026-12-31', PowerChannelParser::parsePost($text, self::now('2027-01-01 08:00'))['date'] ?? null);

        $text = str_replace('28 жовтня', '1 січня', self::POST);
        $this->assertSame('2027-01-01', PowerChannelParser::parsePost($text, self::now('2026-12-31 21:00'))['date'] ?? null);
    }

    public function testThePageKeepsEveryScheduleInPostOrder(): void
    {
        $post = static fn (int $id, string $text) => sprintf(
            '<div class="tgme_widget_message_wrap js-widget_message_wrap"><div class="tgme_widget_message" data-post="pat_cherkasyoblenergo/%d">'
                . '<div class="tgme_widget_message_text js-message_text" dir="auto">%s</div></div></div>',
            $id,
            nl2br(htmlspecialchars($text)),
        );

        $html = $post(1914, self::POST)
            . $post(1912, 'Новина про ремонт')
            . $post(1911, str_replace(['28 жовтня', '3.1 12:00 - 14:00, 17:00 - 19:00'], ['27 жовтня', '3.1 14:00 - 16:00'], self::POST));

        $found = PowerChannelParser::parsePage($html, self::now());

        $this->assertSame([1911, 1914], array_column($found, 'post'));
        $this->assertSame([[840, 960]], $found[0]['queues']['3.1']);
    }

    /** The hourly queue, never the emergency one the same answer also names. */
    public function testTheQueueIsTheHourlyOneNotTheEmergencyOne(): void
    {
        $this->assertSame('3.1', PowerQueueResolver::queueFrom(['DISCONN_QUEUQ' => [
            ['QUEUE_INFO' => 'Графік аварійних відключень - 9 черга'],
            ['QUEUE_INFO' => 'Графік погодинних відключень - 3 черга 1 підчерга'],
        ]]));

        $this->assertNull(PowerQueueResolver::queueFrom(['DISCONN_QUEUQ' => [
            ['QUEUE_INFO' => 'Графік аварійних відключень - 9 черга'],
        ]]));
        $this->assertNull(PowerQueueResolver::queueFrom(['DISCONN_QUEUQ' => [], 'DISCONNECTIONS' => []]));
    }

    /** Buildings in one queue are one line; buildings moved apart get one each. */
    public function testBuildingsAreGroupedByTheirQueue(): void
    {
        $queues = PowerChannelParser::parsePost(self::POST, self::now())['queues'];

        $groups = PowerScheduleAnnouncer::groups(['19' => '3.1', '17' => '3.1', '27' => '4.2'], $queues);

        $this->assertSame(['3.1', '4.2'], array_column($groups, 'queue'));
        $this->assertSame(['17', '19'], $groups[0]['houses']);
        $this->assertSame($queues['4.2'], $groups[1]['outages']);

        $this->assertSame([], PowerScheduleAnnouncer::groups(['19' => '7.1'], $queues));
    }

    public function testTheCaptionSaysWhenItGoesAndWhenItComesBack(): void
    {
        $queues = PowerChannelParser::parsePost(self::POST, self::now())['queues'];
        $groups = PowerScheduleAnnouncer::groups(['17' => '3.1', '19' => '3.1'], $queues);

        $caption = PowerScheduleAnnouncer::caption(self::now()->setTime(0, 0), $groups, false, self::now());

        $this->assertStringContainsString('Світло · середа, 28 жовтня', $caption);
        $this->assertStringContainsString('Черга <b>3.1</b> · буд. 17, 19', $caption);
        $this->assertMatchesRegularExpression('/🔴 12:00 — вимкнуть\n🟢 14:00 — увімкнуть\n🔴 17:00 — вимкнуть\n🟢 19:00 — увімкнуть/u', $caption);
        $this->assertStringContainsString('Без світла: 4 год', $caption);
        $this->assertStringNotContainsString('оновлено', $caption);

        $updated = PowerScheduleAnnouncer::caption(self::now()->setTime(0, 0), $groups, true, self::now('2026-10-28 14:05'));
        $this->assertStringStartsWith('🔄 <b>Графік оновлено о 14:05</b>', $updated);
    }

    public function testADayWithoutOutagesSaysSo(): void
    {
        $caption = PowerScheduleAnnouncer::caption(
            self::now(),
            [['queue' => '3.1', 'houses' => ['19'], 'outages' => []]],
            false,
            self::now(),
        );

        $this->assertStringContainsString('✅ Відключень не заплановано.', $caption);
        $this->assertStringNotContainsString('🔴', $caption);
    }

    public function testDurations(): void
    {
        $this->assertSame('4 год', PowerScheduleAnnouncer::duration(240));
        $this->assertSame('2 год 30 хв', PowerScheduleAnnouncer::duration(150));
        $this->assertSame('30 хв', PowerScheduleAnnouncer::duration(30));
    }

    /** The picture is drawn with the shipped font, not one the server may not have. */
    public function testThePictureIsAPng(): void
    {
        $png = (new PowerScheduleImage(dirname(__DIR__, 2)))->render(
            'Світло · середа, 28 жовтня',
            [['label' => 'Черга 3.1 · буд. 17, 19', 'outages' => [[720, 840], [1020, 1140]]]],
            'Черкасиобленерго',
        );

        $this->assertStringStartsWith("\x89PNG", $png);
        $size = getimagesizefromstring($png);
        $this->assertSame(1200, $size[0]);
    }

    /** @return array<string, list<array{queue: string, houses: list<string>, outages: list<array{int, int}>}>> */
    private static function days(array $today, ?array $tomorrow = null): array
    {
        $days = ['2026-10-28' => [['queue' => '3.1', 'houses' => ['17', '19'], 'outages' => $today]]];

        if ($tomorrow !== null) {
            $days['2026-10-29'] = [['queue' => '3.1', 'houses' => ['17', '19'], 'outages' => $tomorrow]];
        }

        return $days;
    }

    /** Ten minutes before, and not once the light has already gone. */
    public function testTheWarningIsDueInTheTenMinutesBeforeAnOutage(): void
    {
        $days = self::days([[720, 840], [1020, 1140]]);

        $this->assertSame([], PowerScheduleAnnouncer::dueReminders($days, self::now('2026-10-28 11:49')));

        $due = PowerScheduleAnnouncer::dueReminders($days, self::now('2026-10-28 11:50'));
        $this->assertCount(1, $due);
        $this->assertSame('off@3.1@720', $due[0]['key']);
        $this->assertStringStartsWith('⚠️ Через 10 хвилин вимкнуть світло — о 12:00, до 14:00.', $due[0]['text']);

        $this->assertCount(1, PowerScheduleAnnouncer::dueReminders($days, self::now('2026-10-28 11:58')));
        $this->assertSame([], PowerScheduleAnnouncer::dueReminders($days, self::now('2026-10-28 12:00')));
    }

    /** When it comes back, with what is next — later today, tomorrow, or nothing. */
    public function testTheReturnNamesTheNextOutage(): void
    {
        $due = PowerScheduleAnnouncer::dueReminders(self::days([[720, 840], [1020, 1140]]), self::now('2026-10-28 14:02'));
        $this->assertSame('on@3.1@840', $due[0]['key'] ?? null);
        $this->assertStringContainsString("🟢 14:00 — за графіком мають увімкнути світло.\nНаступне відключення — о 17:00.", $due[0]['text']);

        $due = PowerScheduleAnnouncer::dueReminders(self::days([[720, 840], [1020, 1140]], [[360, 480]]), self::now('2026-10-28 19:00'));
        $this->assertStringContainsString('Наступне відключення — завтра о 06:00.', $due[0]['text'] ?? '');

        $due = PowerScheduleAnnouncer::dueReminders(self::days([[1020, 1140]]), self::now('2026-10-28 19:05'));
        $this->assertStringContainsString('Сьогодні відключень більше не заплановано.', $due[0]['text'] ?? '');

        $this->assertSame([], PowerScheduleAnnouncer::dueReminders(self::days([[1020, 1140]]), self::now('2026-10-28 19:10')));
    }

    /** An outage that starts at midnight is warned about the evening before. */
    public function testMidnightIsHandledFromBothSides(): void
    {
        $due = PowerScheduleAnnouncer::dueReminders(self::days([[1200, 1260]], [[0, 120]]), self::now('2026-10-28 23:55'));
        $this->assertSame('off@3.1@0', $due[0]['key'] ?? null);
        $this->assertSame('2026-10-29', $due[0]['date']);

        // 22:00–24:00 followed by 00:00–02:00 is one outage: no «увімкнуть» at midnight.
        $this->assertSame([], PowerScheduleAnnouncer::dueReminders(
            self::days([[1320, 1440]], [[0, 120]]),
            self::now('2026-10-29 00:02'),
        ));
    }
}
