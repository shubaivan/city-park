<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\TelegramUser;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\GuestPass\Command\GuestPassCreate;
use SergiX44\Nutgram\Cache\ConversationCache;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «➕ Новий пропуск» asks for how long, and the answer reaches create() (18.11.2026).
 *
 * Driven through the real config/telegram.php, so it also proves the length buttons are
 * answered by the conversation itself: a callback that matched a registered handler would
 * end the conversation in Nutgram and no pass would be made.
 */
class GuestPassCreateLengthTest extends KernelTestCase
{
    private const USER_ID = 485598263;

    public function testTappingALengthCreatesThePassForThatManyDays(): void
    {
        [$bot, $passes, $cache] = $this->setUpConversation();

        $passes->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(Account::class), $this->anything(), 'AA1234BB · Бригада', 3)
            ->willReturn(null);

        $bot->processUpdate($this->tap(GuestPassCreate::DAYS_PREFIX . '3'));

        $this->assertNull($cache->get(self::USER_ID, self::USER_ID, null), 'the conversation ends once the pass is made');
    }

    public function testTypingInsteadOfTappingAsksAgainAndCreatesNothing(): void
    {
        [$bot, $passes, $cache] = $this->setUpConversation();

        $passes->expects($this->never())->method('create');

        $bot->processUpdate(Update::fromArray([
            'update_id' => 2,
            'message' => [
                'message_id' => 11,
                'date' => time(),
                'from' => ['id' => self::USER_ID, 'is_bot' => false, 'first_name' => 'Resident'],
                'chat' => ['id' => self::USER_ID, 'type' => 'private', 'first_name' => 'Resident'],
                'text' => 'тиждень',
            ],
        ]));

        $this->assertNotNull($cache->get(self::USER_ID, self::USER_ID, null), 'the question stands');
    }

    public function testCancelEndsWithoutAPass(): void
    {
        [$bot, $passes, $cache] = $this->setUpConversation();

        $passes->expects($this->never())->method('create');

        $bot->processUpdate($this->tap('cancel'));

        $this->assertNull($cache->get(self::USER_ID, self::USER_ID, null));
    }

    /** @return array{FakeNutgram, GuestPassService&\PHPUnit\Framework\MockObject\MockObject, ConversationCache} */
    private function setUpConversation(): array
    {
        self::bootKernel();
        $container = self::getContainer();

        $account = (new Account())->setAccountNumber('220085')->setApartmentNumber('85')->setHouseNumber('19');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 7);
        $user = (new TelegramUser())->setTelegramId((string)self::USER_ID);

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($user);
        $users->method('resolveAccount')->willReturn($account);
        $container->set(TelegramUserService::class, $users);

        $passes = $this->createMock(GuestPassService::class);
        $passes->method('mayCreate')->willReturn(true);
        $passes->method('liveFor')->willReturn([]);
        $container->set(GuestPassService::class, $passes);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        $conversation = $container->get(GuestPassCreate::class);
        $conversation->label = 'AA1234BB · Бригада';
        (new \ReflectionProperty(Conversation::class, 'step'))->setValue($conversation, 'saveDays');
        $bot->stepConversation($conversation, self::USER_ID, self::USER_ID);

        return [$bot, $passes, $bot->getContainer()->get(ConversationCache::class)];
    }

    private function tap(string $data): Update
    {
        return Update::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb1',
                'from' => ['id' => self::USER_ID, 'is_bot' => false, 'first_name' => 'Resident'],
                'chat_instance' => 'x',
                'data' => $data,
                'message' => [
                    'message_id' => 10,
                    'date' => time(),
                    'chat' => ['id' => self::USER_ID, 'type' => 'private', 'first_name' => 'Resident'],
                    'text' => '📅 На скільки днів пропуск?',
                ],
            ],
        ]);
    }
}
