<?php

namespace App\Tests\Telegram;

use App\Service\PhotoUploadFlow;
use App\Telegram\SchedulePavilion\Command\SchedulePavilion;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Nothing written in the residents' group may reach a handler built for a private chat.
 *
 * As an administrator of «ЖК City Park» the bot loses Telegram's privacy mode and gets
 * every message posted there. The handlers cannot tell the difference on their own:
 * onPhoto would file a picture from the chat as pavilion evidence and close somebody's
 * photo obligation — most likely the obligation of whoever happened to post a photo of
 * their cat — and a live conversation would read group chatter as an answer to its last
 * question. The rule therefore lives in one middleware in config/telegram.php.
 */
class GroupUpdateGuardTest extends KernelTestCase
{
    private const USER_ID = 485598262;
    private const GROUP_ID = -1002345678901;

    public function testPhotoPostedInTheGroupIsNotTakenAsPavilionEvidence(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $flow = $this->createMock(PhotoUploadFlow::class);
        $flow->expects($this->never())->method('process');
        $flow->expects($this->never())->method('interceptConversationPhoto');
        $container->set(PhotoUploadFlow::class, $flow);

        $bot = $this->fakeBot($container);

        // Worst case: the resident also has a conversation open, which is what routes
        // every one of their updates into it.
        $conversation = $container->get(SchedulePavilion::class);
        (new \ReflectionProperty(Conversation::class, 'step'))->setValue($conversation, 'scheduleDate');
        $bot->stepConversation($conversation, self::USER_ID, self::USER_ID);

        $bot->processUpdate($this->groupPhotoUpdate());
    }

    public function testTheSamePhotoInAPrivateChatStillCounts(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $flow = $this->createMock(PhotoUploadFlow::class);
        $flow->expects($this->once())->method('process');
        $container->set(PhotoUploadFlow::class, $flow);

        $bot = $this->fakeBot($container);

        $bot->processUpdate($this->photoUpdate(self::USER_ID, 'private'));
    }

    private function fakeBot(object $container): FakeNutgram
    {
        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        // Global middleware is attached to the handlers by Nutgram::preflight(), which
        // production reaches through run() in /hook. run() is unusable from a test —
        // config/telegram.php puts the bot in webhook mode, so it would go looking for a
        // request body — and processUpdate() alone skips preflight, which would quietly
        // test a bot with no middleware at all.
        (new \ReflectionMethod($bot, 'preflight'))->invoke($bot);

        return $bot;
    }

    private function groupPhotoUpdate(): Update
    {
        return $this->photoUpdate(self::GROUP_ID, 'supergroup');
    }

    private function photoUpdate(int $chatId, string $chatType): Update
    {
        return Update::fromArray([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'date' => 1756800000,
                'from' => ['id' => self::USER_ID, 'is_bot' => false, 'first_name' => 'Resident'],
                'chat' => ['id' => $chatId, 'type' => $chatType, 'title' => 'ЖК City Park'],
                'photo' => [
                    ['file_id' => 'small', 'file_unique_id' => 'u1', 'width' => 90, 'height' => 90],
                    ['file_id' => 'large', 'file_unique_id' => 'u2', 'width' => 1280, 'height' => 960],
                ],
            ],
        ]);
    }
}
