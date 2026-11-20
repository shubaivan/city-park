<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\GuestPass\Command\GuestPassCommand;
use App\Telegram\GuestPass\Command\GuestPassCreate;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A flat at the cap still sees «➕ Новий пропуск», and the tap says why (20.11.2026).
 *
 * Until then the button simply vanished at the third pass, and Иван — with two forgotten
 * September passes in his list — could not tell why he could not make a new one.
 */
class GuestPassCapTest extends KernelTestCase
{
    private const USER_ID = 485598263;

    public function testTheButtonIsDrawnEvenAtTheCap(): void
    {
        $bot = $this->bot(mayCreate: false, counted: 10);

        $bot->processUpdate($this->tap(GuestPassCommand::MENU_CALLBACK));

        $this->assertStringContainsString(GuestPassCreate::START_CALLBACK, $this->sent($bot));
        $this->assertStringContainsString('➕ Новий пропуск', $this->sent($bot));
    }

    public function testTheTapAtTheCapExplainsWhy(): void
    {
        $bot = $this->bot(mayCreate: false, counted: 10);

        $bot->processUpdate($this->tap(GuestPassCreate::START_CALLBACK));

        $sent = $this->sent($bot);
        $this->assertStringContainsString('вже 10 діючих пропусків — це максимум', $sent);
        $this->assertStringContainsString('понад 30 днів, не рахуються', $sent);
    }

    private function bot(bool $mayCreate, int $counted): FakeNutgram
    {
        self::bootKernel();
        $container = self::getContainer();

        $account = (new Account())->setAccountNumber('230085')->setApartmentNumber('85')->setHouseNumber('23');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 2);
        $user = (new TelegramUser())->setTelegramId((string)self::USER_ID);

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($user);
        $users->method('resolveAccount')->willReturn($account);
        $container->set(TelegramUserService::class, $users);

        $passes = $this->createMock(GuestPassService::class);
        $passes->method('mayCreate')->willReturn($mayCreate);
        $passes->method('countedFor')->willReturn($counted);
        $passes->method('liveFor')->willReturn([]);
        $container->set(GuestPassService::class, $passes);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        return $bot;
    }

    /** Everything the bot sent, as one string: texts and keyboards. */
    private function sent(FakeNutgram $bot): string
    {
        $out = '';

        foreach ($bot->getRequestHistory() as $entry) {
            $body = (string)$entry['request']->getBody();
            $decoded = json_decode($body, true);
            $out .= ($decoded === null ? urldecode($body) : json_encode($decoded, JSON_UNESCAPED_UNICODE)) . "\n";
        }

        return $out;
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
                    'text' => '👷 Пропуски',
                ],
            ],
        ]);
    }
}
