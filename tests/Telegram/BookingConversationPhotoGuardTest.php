<?php

namespace App\Tests\Telegram;

use App\Service\PhotoUploadFlow;
use App\Telegram\SchedulePavilion\Command\OwnSchedule;
use App\Telegram\SchedulePavilion\Command\SchedulePavilion;
use SergiX44\Nutgram\Cache\ConversationCache;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * While a resident has an active (or stale) conversation, Nutgram routes EVERY update
 * from them into that conversation — photos included. The booking conversation guards
 * against it, but the guard used to call Conversation::end(), which reads $this->bot —
 * a property Conversation initialises only inside parent::__invoke() and strips in
 * __serialize(). On a conversation restored from the cache (i.e. always, in production)
 * that threw, /hook answered 500, and Telegram retried the same photo for an hour while
 * the resident got no reply at all. ~950 failed deliveries over 02–03.08 and 16.08.2026,
 * 3 residents blocked for photos they had actually sent.
 */
class BookingConversationPhotoGuardTest extends KernelTestCase
{
    private const USER_ID = 485598262;

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function conversationProvider(): iterable
    {
        yield 'booking' => [SchedulePavilion::class, 'scheduleDate'];
        yield 'own bookings' => [OwnSchedule::class, 'removeScheduled'];
    }

    /**
     * @dataProvider conversationProvider
     */
    public function testStrayPhotoIsHandedToTheUploadFlow(string $conversationClass, string $stuckStep): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $flow = $this->createMock(PhotoUploadFlow::class);
        $flow->expects($this->once())->method('interceptConversationPhoto');
        $container->set(PhotoUploadFlow::class, $flow);

        $bot = $this->fakeBot($container);
        $this->stickConversation($bot, $container->get($conversationClass), $stuckStep);

        $bot->processUpdate($this->photoUpdate());
    }

    /**
     * The regression itself: ending a cache-restored conversation must not touch
     * Conversation::$bot, and nothing in the interception may bubble up into a 500.
     */
    public function testInterceptionEndsTheConversationWithoutThrowing(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $bot = $this->fakeBot($container);
        $this->stickConversation($bot, $container->get(SchedulePavilion::class), 'scheduleDate');

        $bot->processUpdate($this->photoUpdate());

        $this->assertNull(
            $bot->getContainer()->get(ConversationCache::class)->get(self::USER_ID, self::USER_ID, null),
            'the stuck conversation should have been ended, so the next photo reaches onPhoto directly',
        );
    }

    private function fakeBot(object $container): FakeNutgram
    {
        $bot = FakeNutgram::instance();
        // production wiring: Nutgram resolves handler classes from Symfony's container
        $bot->getContainer()->delegate($container);
        // the real bot bootstrap — registers handlers and enables refreshOnDeserialize()
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        return $bot;
    }

    private function stickConversation(FakeNutgram $bot, Conversation $conversation, string $step): void
    {
        (new \ReflectionProperty(Conversation::class, 'step'))->setValue($conversation, $step);
        $bot->stepConversation($conversation, self::USER_ID, self::USER_ID);
    }

    private function photoUpdate(): Update
    {
        return Update::fromArray([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'date' => 1755000000,
                'from' => ['id' => self::USER_ID, 'is_bot' => false, 'first_name' => 'Resident'],
                'chat' => ['id' => self::USER_ID, 'type' => 'private', 'first_name' => 'Resident'],
                'photo' => [
                    ['file_id' => 'small', 'file_unique_id' => 'u1', 'width' => 90, 'height' => 90],
                    ['file_id' => 'large', 'file_unique_id' => 'u2', 'width' => 1280, 'height' => 960],
                ],
            ],
        ]);
    }
}
