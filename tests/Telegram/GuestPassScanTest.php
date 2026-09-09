<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;
use App\Service\GuardService;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\GuestPass\Command\GuestPassScanCommand;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a builder's pass answers at the gate.
 *
 * The middle case is the whole security model: a pass is one day long, so a screenshot
 * from yesterday has to read as **wrong** — not as an error the guard might blame on the
 * bot, and not as a shrug he resolves by letting them in.
 */
class GuestPassScanTest extends KernelTestCase
{
    public function testAnActivatedPassNamesTheCrewAndTheFlat(): void
    {
        $text = $this->scan($this->pass(activeToday: true));

        $this->assertStringContainsString('Пропуск дійсний', $text);
        // With the date, not only the hour: the answer is read as a record — on a
        // screenshot, or a minute after the guard scrolled past another one — and «до
        // 23:59» alone does not say which day the bot was talking about.
        $this->assertMatchesRegularExpression(
            '/Діє до \d{2}\.\d{2} о \d{2}:\d{2}/u',
            $text,
            'the window must carry its date',
        );
        $this->assertMatchesRegularExpression('/Перевірено \d{2}\.\d{2} о \d{2}:\d{2}/u', $text);
        $this->assertStringContainsString('Бригада, ремонт', $text);
        $this->assertStringContainsString('кв. 85', $text, 'the guard must see which flat is expecting them');
        $this->assertStringContainsString('буд. 19', $text);
    }

    public function testAPassOutsideItsWindowIsRefused(): void
    {
        $text = $this->scan($this->pass(activeToday: false));

        $this->assertStringContainsString('не активний', $text);
        $this->assertStringNotContainsString('Пропуск дійсний', $text);
    }

    public function testARevokedPassIsRefusedWithoutNamingTheFlat(): void
    {
        $pass = $this->pass(activeToday: true);
        $pass->revoke();

        $text = $this->scan($pass);

        $this->assertStringContainsString('скасовано', $text);
        $this->assertStringNotContainsString('кв. 85', $text, 'a withdrawn pass tells the scanner nothing');
    }

    /**
     * Whoever opens the link without being a resident is told who opens it instead.
     *
     * Most of them are not lost residents: they are the very builders the pass was made
     * for, tapping their own link out of curiosity. «Ви ще не підтверджені» reads to them
     * as a pass that does not work, and the next thing they do is ring the flat. They
     * still learn nothing about the holder — the flat is not in this answer.
     */
    public function testSomebodyWhoIsNotAResidentIsToldTheGuardOpensIt(): void
    {
        $text = $this->scan($this->pass(activeToday: true), linked: false);

        $this->assertStringContainsString('охорона на вході', $text);
        $this->assertStringNotContainsString('кв. 85', $text, 'a stranger learns nothing about the flat');
        $this->assertStringNotContainsString('Бригада', $text);
    }

    private function scan(GuestPass $pass, bool $linked = true): string
    {
        self::bootKernel();

        $passes = $this->createMock(GuestPassService::class);
        $passes->method('readToken')->willReturn(3);
        $passes->method('find')->willReturn($pass);

        $scanner = new TelegramUser();
        $scanner->setTelegramId('888111');

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($scanner);
        $users->method('resolveAccount')->willReturn($linked ? $this->account(9, '12') : null);

        $guard = new GuardService($this->createMock(ScheduledSetRepository::class), '777000', 'test-secret');

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());

        (new GuestPassScanCommand($passes, $guard, $users))($bot, 'p-3-abcdef012345');

        $sent = '';

        foreach ($bot->getRequestHistory() as $request) {
            $fields = json_decode((string)$request['request']->getBody(), true);
            $sent .= (is_array($fields) ? ($fields['text'] ?? '') : '') . "\n";
        }

        return $sent;
    }

    private function pass(bool $activeToday): GuestPass
    {
        $pass = (new GuestPass())
            ->setAccount($this->account(7, '85'))
            ->setLabel('Бригада, ремонт');

        if ($activeToday) {
            $pass->setActiveUntil(new \DateTime('+2 hours', new \DateTimeZone('Europe/Kyiv')));
        }

        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 3);

        return $pass;
    }

    private function account(int $id, string $apartment): Account
    {
        $account = (new Account())
            ->setAccountNumber('2200' . $apartment)
            ->setApartmentNumber($apartment)
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }
}
