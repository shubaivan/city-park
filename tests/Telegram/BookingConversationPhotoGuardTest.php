<?php

namespace App\Tests\Telegram;

use App\Service\PhotoUploadFlow;
use App\Telegram\SchedulePavilion\Command\SchedulePavilion;
use SergiX44\Nutgram\Cache\ConversationCache;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * While a resident has an active (or stale) booking conversation, Nutgram routes EVERY
 * update from them into that conversation — photos included. SchedulePavilion guards
 * against it, but the guard used to call Conversation::end(), which reads $this->bot —
 * a property Conversation initialises only inside parent::__invoke() and strips in
 * __serialize(). On a conversation restored from the cache (i.e. always, in production)
 * that threw, /hook answered 500, and Telegram retried the same photo for an hour while
 * the resident got no reply at all. ~950 failed deliveries over 02–03.08 and 16.08.2026.
 */
class BookingConversationPhotoGuardTest extends KernelTestCase
{
    private const USER_ID = 485598262;

    public function testPhotoOnRestoredBookingConversationIsHandledInsteadOfCrashing(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $flow = $this->createMock(PhotoUploadFlow::class);
        $flow->expects($this->once())->method('process');
        $container->set(PhotoUploadFlow::class, $flow);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        // the real bot bootstrap — registers handlers and enables refreshOnDeserialize()
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        // a conversation stuck mid-booking, as it sits in the conversation cache
        $conversation = $container->get(SchedulePavilion::class);
        (new \ReflectionProperty(Conversation::class, 'step'))->setValue($conversation, 'scheduleDate');
        $bot->stepConversation($conversation, self::USER_ID, self::USER_ID);

        $bot->processUpdate($this->photoUpdate());

        // the booking conversation must be gone, so a follow-up photo reaches onPhoto directly
        $this->assertNull(
            $bot->getContainer()->get(ConversationCache::class)->get(self::USER_ID, self::USER_ID, null),
            'the stuck booking conversation should have been ended',
        );
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
