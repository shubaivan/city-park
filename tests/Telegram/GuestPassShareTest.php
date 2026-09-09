<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\TelegramUser;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\GuestPass\Command\GuestPassCommand;
use SergiX44\Nutgram\Testing\FakeNutgram;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «📤 Переслати робітникам» hands over the QR itself, and nothing but it.
 *
 * What the бригадир does with this message is stand at the gate and hold up his phone —
 * a guard cannot scan a url. The button shipped as text and a link on 09.09.2026 and had
 * to be corrected the same day; «зробити його текстовим, як на інших дошках» is exactly
 * the tidy-up that would undo it, because on every other board the shared thing really is
 * text.
 */
class GuestPassShareTest extends KernelTestCase
{
    public function testTheForwardableCopyIsThePictureWithTheTextUnderIt(): void
    {
        [$method, $body] = $this->share();

        $this->assertStringContainsString('sendPhoto', $method, 'a link cannot be scanned at the gate');
        $this->assertStringContainsString('Бригада, ремонт', $body);
        $this->assertStringContainsString('кв. 85', $body, 'the crew has to be able to say where they are expected');
        // The url was in the caption for one version. Nobody in this exchange would use
        // it — the бригадир will not forward a link and the guard will not type one — and
        // printed under a code it invites sending the link instead of the code.
        $this->assertStringNotContainsString('t.me/', $body, 'a link under the code is a link somebody will send instead of it');
    }

    /** @return array{string, string} the last method called and its raw body */
    private function share(): array
    {
        self::bootKernel();

        $pass = (new GuestPass())
            ->setAccount($this->account())
            ->setLabel('Бригада, ремонт');
        $pass->setActiveUntil(new \DateTime('+2 hours', new \DateTimeZone('Europe/Kyiv')));
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 3);

        $passes = $this->createMock(GuestPassService::class);
        $passes->method('find')->willReturn($pass);
        $passes->method('mintToken')->willReturn('p-3-abc123');

        $resident = new TelegramUser();
        $resident->setTelegramId('777111');

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($resident);
        $users->method('resolveAccount')->willReturn($pass->getAccount());

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());
        $bot->willReceive(true);
        $bot->willReceive(['id' => 42, 'is_bot' => true, 'first_name' => 'City Park', 'username' => 'che_city_park_bot']);
        $bot->willReceive(['message_id' => 7, 'date' => time(), 'chat' => ['id' => 777111, 'type' => 'private']]);

        $command = new GuestPassCommand($passes, $users);
        $bot->onCallbackQueryData(GuestPassCommand::SHARE_PREFIX . '3', fn (Nutgram $b) => $command($b));
        $bot->hearCallbackQueryData(GuestPassCommand::SHARE_PREFIX . '3');
        $bot->run();

        $history = $bot->getRequestHistory();
        $last = end($history);

        return [
            (string)$last['request']->getUri(),
            (string)$last['request']->getBody(),
        ];
    }

    private function account(): Account
    {
        $account = (new Account())
            ->setAccountNumber('220085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 7);

        return $account;
    }
}
