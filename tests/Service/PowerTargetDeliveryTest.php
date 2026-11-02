<?php

namespace App\Tests\Service;

use App\Entity\PowerSchedulePost;
use App\Repository\PowerQueueRepository;
use App\Repository\PowerSchedulePostRepository;
use App\Service\PowerQueueResolver;
use App\Service\PowerScheduleAnnouncer;
use App\Service\PowerScheduleImage;
use App\Service\PowerTargets;
use App\Service\ResidentChatService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The schedule reaches each chat where that chat reads it: the residents' into their topic
 * as before, the street's group straight in, and one chat's failure stays its own.
 */
class PowerTargetDeliveryTest extends TestCase
{
    private const RESIDENTS_CHAT = '-1001111111111';
    private const GROUP_CHAT = '-1002222222222';
    private const TOPIC = 455;

    /** @var list<array<string, mixed>> */
    private array $photos = [];

    /** @var list<PowerSchedulePost> */
    private array $stored = [];

    public function testBothChatsGetTheirPostAndOnlyTheResidentsATopic(): void
    {
        $this->announcer(self::GROUP_CHAT)->run();

        $this->assertCount(2, $this->photos);

        [$residents, $group] = $this->photos;
        $this->assertSame((int)self::RESIDENTS_CHAT, $residents['chat_id']);
        $this->assertSame(self::TOPIC, $residents['message_thread_id']);
        $this->assertStringContainsString('буд. 17, 19', $residents['caption']);

        $this->assertSame((int)self::GROUP_CHAT, $group['chat_id']);
        $this->assertNull($group['message_thread_id']);
        $this->assertStringContainsString('пров. Західний', $group['caption']);
        $this->assertStringNotContainsString('буд.', $group['caption']);
    }

    public function testWithoutTheGroupConfiguredOnlyTheResidentsAreTouched(): void
    {
        $this->announcer('')->run();

        $this->assertCount(1, $this->photos);
        $this->assertSame((int)self::RESIDENTS_CHAT, $this->photos[0]['chat_id']);
    }

    public function testAFailureInTheGroupCostsTheResidentsNothing(): void
    {
        $this->announcer(self::GROUP_CHAT, failGroup: true)->run();

        $this->assertCount(1, $this->photos);
        $this->assertSame((int)self::RESIDENTS_CHAT, $this->photos[0]['chat_id']);
    }

    /** The group gets the daily post and its updates, but no «через 15 хвилин». */
    public function testRemindersGoToTheResidentsOnly(): void
    {
        $tz = new \DateTimeZone('Europe/Kyiv');
        $now = new \DateTimeImmutable('2026-11-02 11:50', $tz);
        $signature = json_encode([['queue' => '3.1', 'houses' => ['17'], 'outages' => [[720, 840]]]]);

        $chat = $this->createMock(ResidentChatService::class);
        $chat->method('isConfigured')->willReturn(true);
        $chat->method('chatId')->willReturn(self::RESIDENTS_CHAT);
        $chat->method('topic')->willReturn(self::TOPIC);
        $targets = new PowerTargets($chat, self::GROUP_CHAT);

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendMessage');

        $posts = $this->createMock(PowerSchedulePostRepository::class);
        $posts->method('around')->willReturn(['2026-11-02' => new PowerSchedulePost(new \DateTimeImmutable('2026-11-02', $tz), $signature, 1)]);

        $announcer = new PowerScheduleAnnouncer(
            $bot,
            $chat,
            $targets,
            new MockHttpClient(),
            $this->createMock(PowerQueueRepository::class),
            $posts,
            $this->createMock(PowerQueueResolver::class),
            new PowerScheduleImage(dirname(__DIR__, 2)),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            sys_get_temp_dir(),
        );

        $remind = new \ReflectionMethod(PowerScheduleAnnouncer::class, 'remind');

        $this->assertSame([], $remind->invoke($announcer, $now, $targets->others()[0], false));
        $this->assertCount(1, $remind->invoke($announcer, $now, $targets->residents(), false));
    }

    private function announcer(string $groupChat, bool $failGroup = false): PowerScheduleAnnouncer
    {
        $chat = $this->createMock(ResidentChatService::class);
        $chat->method('isConfigured')->willReturn(true);
        $chat->method('chatId')->willReturn(self::RESIDENTS_CHAT);
        $chat->method('topic')->willReturn(self::TOPIC);

        $bot = $this->createMock(Nutgram::class);
        $names = array_map(static fn (\ReflectionParameter $p) => $p->getName(), (new \ReflectionMethod(Nutgram::class, 'sendPhoto'))->getParameters());
        $bot->method('sendPhoto')->willReturnCallback(function (...$values) use ($names, $failGroup) {
            $args = array_combine(array_slice($names, 0, count($values)), $values);

            if ($failGroup && ($args['chat_id'] ?? null) === (int)self::GROUP_CHAT) {
                throw new \RuntimeException('Forbidden: bot was kicked');
            }

            $this->photos[] = $args + ['message_thread_id' => null];
            $message = (new \ReflectionClass(Message::class))->newInstanceWithoutConstructor();
            $message->message_id = count($this->photos);

            return $message;
        });

        $queues = $this->createMock(PowerQueueRepository::class);
        $queues->method('known')->willReturnCallback(
            static fn (string $target = 'residents') => $target === 'residents' ? ['17' => '3.1', '19' => '3.1'] : ['6' => '3.1'],
        );

        $posts = $this->createMock(PowerSchedulePostRepository::class);
        $posts->method('around')->willReturn([]);
        $posts->method('forDay')->willReturn(null);

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Kyiv')));
        static $months = [1 => 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
        $text = sprintf("Графік погодинних відключень (ГПВ) на %d %s\n\nГодини відсутності електропостачання:\n\n1.1 10:00 - 12:00\n\n2.1 10:00 - 13:00\n\n3.1 23:00 - 24:00\n\n4.1 13:00 - 16:00\n\n5.1 11:00 - 13:00\n\n6.1 09:00 - 11:00\n", (int)$today->format('j'), $months[(int)$today->format('n')]);
        $html = '<div class="tgme_widget_message_wrap js-widget_message_wrap"><div class="tgme_widget_message" data-post="pat_cherkasyoblenergo/1">'
            . '<div class="tgme_widget_message_text js-message_text" dir="auto">' . nl2br(htmlspecialchars($text)) . '</div></div></div>';

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $row): void {
            $this->stored[] = $row;
        });

        return new PowerScheduleAnnouncer(
            $bot,
            $chat,
            new PowerTargets($chat, $groupChat),
            new MockHttpClient(new MockResponse($html)),
            $queues,
            $posts,
            $this->createMock(PowerQueueResolver::class),
            new PowerScheduleImage(dirname(__DIR__, 2)),
            $em,
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }
}
