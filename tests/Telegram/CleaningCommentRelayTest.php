<?php

namespace App\Tests\Telegram;

use App\Entity\CleaningComment;
use App\Entity\ServiceStaff;
use App\Message\CleaningCommentFlushMessage;
use App\Repository\CleaningCommentRepository;
use App\Repository\ServiceStaffRepository;
use App\Service\CleaningCommentRelay;
use App\Service\PhotoUploadFlow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * «🧹 Прибирання» comments reach the cleaning crew anonymously (Иван, 16.11.2026): the words
 * and photos travel, nobody's name, @username, flat or phone does; the bot's own reports,
 * service messages and the crew's own messages stay put; a burst becomes one message.
 */
class CleaningCommentRelayTest extends KernelTestCase
{
    private const CHAT = '-1004443785072';
    private const TOPIC = 810;
    private const STAFF_TG = '777000111';

    /** @var object[] */
    private array $persisted = [];
    private int $dispatched = 0;

    private function relay(
        ?CleaningCommentRepository $comments = null,
        ?ServiceStaffRepository $staff = null,
        ?Nutgram $bot = null,
    ): CleaningCommentRelay {
        $comments ??= $this->createMock(CleaningCommentRepository::class);

        if ($staff === null) {
            $staff = $this->createMock(ServiceStaffRepository::class);
            $staff->method('findActiveByTelegramId')->willReturnCallback(
                static fn (?string $id): ?ServiceStaff => $id === self::STAFF_TG ? new ServiceStaff('+380670000001') : null,
            );
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $m): Envelope {
            $this->assertInstanceOf(CleaningCommentFlushMessage::class, $m);
            ++$this->dispatched;

            return new Envelope($m);
        });

        return new CleaningCommentRelay(
            $comments,
            $staff,
            $em,
            $bus,
            $bot ?? $this->createMock(Nutgram::class),
            new NullLogger(),
            self::CHAT,
            (string)self::TOPIC,
            0,
        );
    }

    private function message(array $override = []): Message
    {
        return Message::fromArray(array_replace([
            'message_id' => 900,
            'message_thread_id' => self::TOPIC,
            'is_topic_message' => true,
            'date' => 1794854400,
            'from' => ['id' => 485598262, 'is_bot' => false, 'first_name' => 'Іван', 'username' => 'ivan_resident'],
            'chat' => ['id' => (int)self::CHAT, 'type' => 'supergroup', 'title' => 'ЖК City Park', 'is_forum' => true],
            'text' => 'У третьому під\'їзді знову брудно',
        ], $override));
    }

    public function testMentionsLinksAndPhonesAreCutOut(): void
    {
        // «Дякую 🙏 » before the mention: the emoji is two UTF-16 units, the offset counts it.
        $text = 'Дякую 🙏 @olena_k і Петро, тел. +380 67 123 45 67, t.me/olena_k — сходи чисті';
        $entities = [
            ['type' => 'mention', 'offset' => 9, 'length' => 8],
            ['type' => 'text_mention', 'offset' => 20, 'length' => 5, 'user' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Петро']],
        ];
        $m = $this->message(['text' => $text, 'entities' => $entities]);

        $clean = CleaningCommentRelay::anonymise($m->text, $m->entities);

        $this->assertSame('Дякую 🙏 і , тел. , — сходи чисті', $clean);
        $this->assertStringNotContainsString('olena', $clean);
        $this->assertStringNotContainsString('Петро', $clean);
        $this->assertStringNotContainsString('123', $clean);
    }

    public function testOrdinaryNumbersStay(): void
    {
        $this->assertSame('пишіть на', CleaningCommentRelay::anonymise('пишіть на olena.k@gmail.com', null));
        $this->assertSame("Під'їзди 1 2 3\n4 5 6\n7 8 9", CleaningCommentRelay::anonymise("Під'їзди 1 2 3\n4 5 6\n7 8 9", null));
        $this->assertSame('Буд. 21, кв. 85, 3 поверх о 10:30', CleaningCommentRelay::anonymise('Буд. 21, кв. 85, 3 поверх о 10:30', null));
    }

    public function testAResidentCommentIsStoredWithoutTheAuthorAndQueued(): void
    {
        $this->relay()->capture($this->message());

        $this->assertCount(1, $this->persisted);
        $row = $this->persisted[0];
        $this->assertInstanceOf(CleaningComment::class, $row);
        $this->assertSame('У третьому під\'їзді знову брудно', $row->getText());
        $this->assertNull($row->getReportAt());
        $this->assertSame(1, $this->dispatched);

        // Nothing in the row can name the author: no field holds a user, name or phone.
        $fields = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(CleaningComment::class))->getProperties());
        $this->assertSame(['id', 'chat_message_id', 'media_group_id', 'text', 'file_id', 'report_at', 'created_at', 'relayed_at'], $fields);
    }

    public function testAReplyToAPhotoReportCarriesTheReportTime(): void
    {
        $this->relay()->capture($this->message([
            'text' => 'Тут пропустили сходи',
            'reply_to_message' => [
                'message_id' => 850,
                'message_thread_id' => self::TOPIC,
                'date' => 1794852900, // 16.11.2026 18:15 UTC = 20:15 Kyiv
                'from' => ['id' => 6707987350, 'is_bot' => true, 'first_name' => 'city_park'],
                'chat' => ['id' => (int)self::CHAT, 'type' => 'supergroup'],
                'photo' => [['file_id' => 'r', 'file_unique_id' => 'r', 'width' => 1, 'height' => 1]],
                'caption' => '🧹 Клінінг · 16.11.2026, 20:15',
            ],
        ]));

        $this->assertSame('16.11.2026, 20:15', $this->persisted[0]->getReportAt()->format('d.m.Y, H:i'));
    }

    public function testTheTopicRootIsNotAReply(): void
    {
        $this->relay()->capture($this->message([
            'reply_to_message' => [
                'message_id' => self::TOPIC,
                'date' => 1763300000,
                'from' => ['id' => 6707987350, 'is_bot' => true, 'first_name' => 'city_park'],
                'chat' => ['id' => (int)self::CHAT, 'type' => 'supergroup'],
                'forum_topic_created' => ['name' => '🧹 Прибирання', 'icon_color' => 1],
            ],
        ]));

        $this->assertNull($this->persisted[0]->getReportAt());
    }

    public function testTheBoardPostingAsTheGroupIsRelayed(): void
    {
        $this->relay()->capture($this->message([
            'from' => ['id' => 1087968824, 'is_bot' => true, 'first_name' => 'Group', 'username' => 'GroupAnonymousBot'],
            'sender_chat' => ['id' => (int)self::CHAT, 'type' => 'supergroup', 'title' => 'ЖК City Park'],
        ]));

        $this->assertCount(1, $this->persisted);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function skipped(): iterable
    {
        yield 'another topic' => [['message_thread_id' => 385]];
        yield 'General (no topic)' => [['message_thread_id' => null, 'is_topic_message' => null]];
        yield 'another chat' => [['chat' => ['id' => -100999, 'type' => 'supergroup']]];
        yield 'a bot (our own report)' => [['from' => ['id' => 6707987350, 'is_bot' => true, 'first_name' => 'city_park']]];
        yield 'the cleaning crew itself' => [['from' => ['id' => (int)self::STAFF_TG, 'is_bot' => false, 'first_name' => 'Клінінг']]];
        yield 'a linked channel' => [['sender_chat' => ['id' => -100555, 'type' => 'channel']]];
        yield 'a service message' => [['text' => null, 'new_chat_members' => [['id' => 5, 'is_bot' => false, 'first_name' => 'X']]]];
        yield 'only a mention' => [['text' => '@olena_k', 'entities' => [['type' => 'mention', 'offset' => 0, 'length' => 8]]]];
        yield 'a sticker' => [['text' => null, 'sticker' => ['file_id' => 's', 'file_unique_id' => 's', 'type' => 'regular', 'width' => 1, 'height' => 1, 'is_animated' => false, 'is_video' => false]]];
    }

    /** @dataProvider skipped */
    public function testSkipped(array $override): void
    {
        $this->relay()->capture($this->message($override));

        $this->assertSame([], $this->persisted);
        $this->assertSame(0, $this->dispatched);
    }

    public function testAWebhookRetryIsNotStoredTwice(): void
    {
        $comments = $this->createMock(CleaningCommentRepository::class);
        $comments->method('findByChatMessage')->willReturn(new CleaningComment(900, 'x', null, null, null, new \DateTime()));

        $this->relay($comments)->capture($this->message());

        $this->assertSame([], $this->persisted);
    }

    private function row(int $id, ?string $text, ?string $fileId = null, string $age = '-30 seconds', ?string $group = null, ?\DateTime $report = null): CleaningComment
    {
        return new CleaningComment($id, $text, $fileId, $group, $report, new \DateTime($age));
    }

    private function staffRepo(): ServiceStaffRepository
    {
        $worker = (new ServiceStaff('+380670000001'))->link(self::STAFF_TG);
        $repo = $this->createMock(ServiceStaffRepository::class);
        $repo->method('findReachableCleaning')->willReturn([$worker]);

        return $repo;
    }

    private function comments(array $rows): CleaningCommentRepository
    {
        $repo = $this->createMock(CleaningCommentRepository::class);
        $repo->method('findUnrelayed')->willReturn($rows);

        return $repo;
    }

    public function testOneCommentGoesAsOneMessage(): void
    {
        $rows = [$this->row(1, 'Під\'їзд 3, сміття біля ліфта', report: new \DateTime('2026-11-16 20:15', new \DateTimeZone('Europe/Kyiv')))];

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendMessage')->with(
            "💬 Коментар у «Прибиранні» до звіту від 16.11.2026, 20:15:\nПід'їзд 3, сміття біля ліфта",
            self::STAFF_TG,
        );
        $bot->expects($this->never())->method('sendPhoto');

        $this->relay($this->comments($rows), $this->staffRepo(), $bot)->flush();

        $this->assertNotNull($rows[0]->getRelayedAt());
    }

    public function testACommentWithAPhotoIsOnePhotoWithTheTextUnder(): void
    {
        $rows = [$this->row(1, 'Ось тут', 'file-1')];

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendPhoto')->with('file-1', self::STAFF_TG, null, "💬 Коментар у «Прибиранні»:\nОсь тут");
        $bot->expects($this->never())->method('sendMessage');

        $this->relay($this->comments($rows), $this->staffRepo(), $bot)->flush();
    }

    public function testABurstIsBatchedIntoOneMessage(): void
    {
        $rows = [
            $this->row(1, 'Перше'),
            $this->row(2, 'Друге'),
            $this->row(3, 'Третє'),
        ];

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendMessage')->with(
            $this->callback(static fn (string $t): bool => str_starts_with($t, '💬 Нові коментарі у «Прибиранні» (3):')
                && str_contains($t, 'Перше') && str_contains($t, 'Друге') && str_contains($t, 'Третє')),
            self::STAFF_TG,
        );

        $this->relay($this->comments($rows), $this->staffRepo(), $bot)->flush();
    }

    public function testAResidentAlbumIsOneCommentWithAllItsPhotos(): void
    {
        $rows = [
            $this->row(1, 'Двір', 'a', group: 'g1'),
            $this->row(2, null, 'b', group: 'g1'),
            $this->row(3, null, 'c', group: 'g1'),
        ];

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendMediaGroup')->with(
            $this->callback(static fn (array $media): bool => count($media) === 3
                && $media[0]->caption === "💬 Коментар у «Прибиранні»:\nДвір"
                && $media[1]->caption === null),
            self::STAFF_TG,
        );
        $bot->expects($this->never())->method('sendMessage');

        $this->relay($this->comments($rows), $this->staffRepo(), $bot)->flush();
    }

    public function testNothingIsSentWhileTheTopicIsStillBusy(): void
    {
        $rows = [$this->row(1, 'Перше', age: '-20 seconds'), $this->row(2, 'Щойно', age: 'now')];

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendMessage');

        $this->relay($this->comments($rows), $this->staffRepo(), $bot)->flush();

        $this->assertNull($rows[0]->getRelayedAt());
        $this->assertSame(1, $this->dispatched, 'a busy topic is looked at again, not left to chance');
    }

    public function testATopicThatNeverGoesQuietStillGetsThrough(): void
    {
        $rows = [$this->row(1, 'Давно', age: '-2 minutes'), $this->row(2, 'Щойно', age: 'now')];

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendMessage');

        $this->relay($this->comments($rows), $this->staffRepo(), $bot)->flush();
    }

    public function testAReplyToARelayedMessageIsRecognised(): void
    {
        $relay = $this->relay();
        $reply = static fn (string $quoted, array $extra = []): Message => Message::fromArray(array_replace([
            'message_id' => 5,
            'date' => 1794854400,
            'chat' => ['id' => (int)self::STAFF_TG, 'type' => 'private'],
            'from' => ['id' => (int)self::STAFF_TG, 'is_bot' => false, 'first_name' => 'Клінінг'],
            'text' => 'Зроблю',
            'reply_to_message' => [
                'message_id' => 4,
                'date' => 1763315000,
                'chat' => ['id' => (int)self::STAFF_TG, 'type' => 'private'],
                'from' => ['id' => 6707987350, 'is_bot' => true, 'first_name' => 'city_park'],
                'text' => $quoted,
            ],
        ], $extra));

        $this->assertTrue($relay->isReplyToRelay($reply("💬 Коментар у «Прибиранні»:\nБрудно")));
        $this->assertTrue($relay->isReplyToRelay($reply('💬 Нові коментарі у «Прибиранні» (2):')));
        $this->assertFalse($relay->isReplyToRelay($reply('✅ Фотозвіт надіслано')));
        $this->assertFalse($relay->isReplyToRelay($reply('💬 Коментар у «Прибиранні»:', ['text' => '/start'])));
        $this->assertTrue($relay->isReplyToRelay($reply('', ['reply_to_message' => [
            'message_id' => 4,
            'date' => 1794854000,
            'chat' => ['id' => (int)self::STAFF_TG, 'type' => 'private'],
            'from' => ['id' => 6707987350, 'is_bot' => true, 'first_name' => 'city_park'],
            'photo' => [['file_id' => 'p', 'file_unique_id' => 'p', 'width' => 1, 'height' => 1]],
        ]])));
        // A photo sent as a reply is still a report.
        $this->assertFalse($relay->isReplyToRelay($reply('💬 Коментар у «Прибиранні»:', [
            'text' => null,
            'photo' => [['file_id' => 'p', 'file_unique_id' => 'p', 'width' => 1, 'height' => 1]],
        ])));
    }

    // ---- wiring through config/telegram.php ------------------------------------------------

    private function fakeBot(object $container): FakeNutgram
    {
        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';
        (new \ReflectionMethod($bot, 'preflight'))->invoke($bot);

        return $bot;
    }

    public function testAGroupMessageReachesTheRelayAndNoPrivateHandler(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $relay = $this->createMock(CleaningCommentRelay::class);
        $relay->expects($this->once())->method('capture')->with($this->isInstanceOf(Message::class));
        $relay->expects($this->never())->method('isReplyToRelay');
        $container->set(CleaningCommentRelay::class, $relay);

        $flow = $this->createMock(PhotoUploadFlow::class);
        $flow->expects($this->never())->method('process');
        $container->set(PhotoUploadFlow::class, $flow);

        $bot = $this->fakeBot($container);
        $bot->processUpdate(Update::fromArray([
            'update_id' => 1,
            'message' => [
                'message_id' => 901,
                'message_thread_id' => self::TOPIC,
                'is_topic_message' => true,
                'date' => 1794854400,
                'from' => ['id' => 485598262, 'is_bot' => false, 'first_name' => 'Іван'],
                'chat' => ['id' => (int)self::CHAT, 'type' => 'supergroup', 'title' => 'ЖК City Park', 'is_forum' => true],
                'caption' => 'Ось',
                'photo' => [['file_id' => 'p', 'file_unique_id' => 'p', 'width' => 1, 'height' => 1]],
            ],
        ]));
    }

    /** A plain text matches no handler at all — the fallback is what lets the middleware see it. */
    public function testAPlainTextInTheTopicReachesTheRelay(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $relay = $this->createMock(CleaningCommentRelay::class);
        $relay->expects($this->once())->method('capture')
            ->with($this->callback(static fn (Message $m): bool => $m->text === 'Брудно біля ліфта'));
        $container->set(CleaningCommentRelay::class, $relay);

        $bot = $this->fakeBot($container);
        $bot->processUpdate(Update::fromArray([
            'update_id' => 3,
            'message' => [
                'message_id' => 902,
                'message_thread_id' => self::TOPIC,
                'is_topic_message' => true,
                'date' => 1794854400,
                'from' => ['id' => 485598262, 'is_bot' => false, 'first_name' => 'Іван'],
                'chat' => ['id' => (int)self::CHAT, 'type' => 'supergroup', 'title' => 'ЖК City Park', 'is_forum' => true],
                'text' => 'Брудно біля ліфта',
            ],
        ]));
    }

    public function testAWorkersReplyIsAnsweredNotLost(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $relay = $this->createMock(CleaningCommentRelay::class);
        // The refusal itself is not visible here: with config/telegram.php loaded FakeNutgram
        // records no requests (webhook mode). That the reply is routed to the check — and
        // nowhere else — is what this pins; the wording is the constant.
        $relay->expects($this->once())->method('isReplyToRelay')->willReturn(true);
        $relay->expects($this->never())->method('capture');
        $container->set(CleaningCommentRelay::class, $relay);

        $bot = $this->fakeBot($container);
        $bot->processUpdate(Update::fromArray([
            'update_id' => 2,
            'message' => [
                'message_id' => 5,
                'date' => 1794854400,
                'chat' => ['id' => (int)self::STAFF_TG, 'type' => 'private'],
                'from' => ['id' => (int)self::STAFF_TG, 'is_bot' => false, 'first_name' => 'Клінінг'],
                'text' => 'Зроблю',
                'reply_to_message' => [
                    'message_id' => 4,
                    'date' => 1763315000,
                    'chat' => ['id' => (int)self::STAFF_TG, 'type' => 'private'],
                    'from' => ['id' => 6707987350, 'is_bot' => true, 'first_name' => 'city_park'],
                    'text' => '💬 Коментар у «Прибиранні»:',
                ],
            ],
        ]));
    }
}
