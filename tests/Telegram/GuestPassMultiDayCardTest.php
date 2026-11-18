<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\TelegramUser;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\GuestPass\Command\GuestPassCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A pass switched on for days shows its last day — on the card, in the forwarded copy, and
 * offers «📅 Сьогодні й завтра / 3 дні / Тиждень» to switch it on again (18.11.2026).
 */
class GuestPassMultiDayCardTest extends KernelTestCase
{
    public function testTheCardOfAWeekPassShowsTheLastDayAndTheDayButtons(): void
    {
        $end = GuestPassService::endOfDays(7);
        $body = $this->press(GuestPassCommand::CARD_PREFIX . '3', $end);

        $this->assertStringContainsString('Діє до ' . $end->format('d.m') . ' о 23:59', $body);
        $this->assertStringContainsString('ще 7 днів, включно із сьогоднішнім', $body);
        $this->assertStringContainsString('pass:days:3:2', $body);
        $this->assertStringContainsString('pass:days:3:3', $body);
        $this->assertStringContainsString('pass:days:3:7', $body);
        $this->assertStringContainsString('Лише до кінця сьогодні', $body, 'on a week pass the «до кінця дня» button shortens it and says so');
        $this->assertStringContainsString('Лише 2 години', $body, 'an hour window on a week pass replaces the week, and the button says so');
        $this->assertStringContainsString('pass:del:3', $body, 'it can still be cancelled early');
        $this->assertStringContainsString('pass:off:3', $body, 'and switched off now');
    }

    public function testTheForwardedCopySaysTheLastDay(): void
    {
        $end = GuestPassService::endOfDays(3);
        $body = $this->press(GuestPassCommand::SHARE_PREFIX . '3', $end);

        $this->assertStringContainsString('Дійсний: до ' . $end->format('d.m') . ' о 23:59', $body);
    }

    public function testADayButtonSwitchesThePassOnForThatManyDays(): void
    {
        $this->press(GuestPassCommand::DAYS_PREFIX . '3:3', null, function (GuestPassService $mock): void {
            $mock->expects($this->once())->method('activateDays')->with($this->isInstanceOf(GuestPass::class), 3);
        });
    }

    private function press(string $data, ?\DateTime $until, ?\Closure $expect = null): string
    {
        self::bootKernel();

        $account = (new Account())->setAccountNumber('220085')->setApartmentNumber('85')->setHouseNumber('19')->setStreet('Козацька');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 7);

        $pass = (new GuestPass())->setAccount($account)->setLabel('Бригада, ремонт');
        $pass->setActiveUntil($until);
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 3);

        $passes = $this->createMock(GuestPassService::class);
        $passes->method('find')->willReturn($pass);
        $passes->method('mintToken')->willReturn('p-3-abc123');
        $passes->method('scansOf')->willReturn([]);
        $expect?->__invoke($passes);

        $resident = (new TelegramUser())->setTelegramId('777111');
        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($resident);
        $users->method('resolveAccount')->willReturn($account);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());
        $bot->willReceive(true);
        $bot->willReceive(['id' => 42, 'is_bot' => true, 'first_name' => 'City Park', 'username' => 'che_city_park_bot']);
        $bot->willReceive(['message_id' => 7, 'date' => time(), 'chat' => ['id' => 777111, 'type' => 'private']]);

        $command = new GuestPassCommand($passes, $users);
        $bot->onCallbackQueryData($data, fn (Nutgram $b) => $command($b));
        $bot->hearCallbackQueryData($data);
        $bot->run();

        $all = '';
        foreach ($bot->getRequestHistory() as $entry) {
            $all .= (string)$entry['request']->getBody() . "\n";
        }

        // Bodies are JSON or multipart (sendPhoto); decode \u escapes so Ukrainian reads plain.
        return (string)preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $all);
    }
}
