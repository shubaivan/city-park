<?php

namespace App\Tests\Telegram;

use App\Command\BotMenuUpdateCommand;
use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;
use App\Service\GuardService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GuardPanelLoginCommand;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The post's phone sees «🔐 Вхід у панель» and nothing else (Иван, 15.11.2026); a resident
 * — and a guard who also lives here — keep the full menu.
 */
class GuardOnlyMenuTest extends KernelTestCase
{
    private const GUARD_ID = '8889990001';

    /** @return string[] "text -> callback" for every button, in order */
    private function menu(string $telegramId, ?Account $account): array
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->set(GuardService::class, new GuardService(
            $this->createMock(ScheduledSetRepository::class),
            self::GUARD_ID,
        ));

        $users = $container->get(TelegramUserService::class);
        $current = new \ReflectionProperty(TelegramUserService::class, 'currentUser');
        $current->setValue($users, (new TelegramUser())->setTelegramId($telegramId));

        $markup = new \ReflectionMethod(StartCommand::class, 'mainMenuMarkup');

        $buttons = [];

        foreach ($markup->invoke(null, $container->get(Nutgram::class), $account)->inline_keyboard as $row) {
            foreach ($row as $button) {
                $buttons[] = $button->text . ' -> ' . ($button->callback_data ?? '?');
            }
        }

        return $buttons;
    }

    private function account(): Account
    {
        $account = (new Account())
            ->setAccountNumber('8-8-0-002')
            ->setApartmentNumber('2')
            ->setHouseNumber('21')
            ->setStreet('Козацька');
        $account->setIsActive(true);

        return $account;
    }

    public function testAGuardWithoutAFlatSeesOnlyThePanelLogin(): void
    {
        $this->assertSame(
            ['🔐 Вхід у панель -> ' . GuardPanelLoginCommand::MENU_CALLBACK],
            $this->menu(self::GUARD_ID, null),
        );
    }

    public function testAResidentKeepsTheFullMenuWithoutGuardButtons(): void
    {
        $menu = implode("\n", $this->menu('5550001', $this->account()));

        foreach (['📅 Бронювання альтанки', 'ℹ️ Інструкція та FAQ', '🗺 Як доїхати?', '💸 Звіт боржників', '🔑 Оренда'] as $label) {
            $this->assertStringContainsString($label, $menu);
        }
        $this->assertStringNotContainsString('🔐 Вхід у панель', $menu);
    }

    public function testAGuardWhoLivesHereKeepsHisFlatAndGetsTheGuardButtons(): void
    {
        $menu = implode("\n", $this->menu(self::GUARD_ID, $this->account()));

        $this->assertStringContainsString('📅 Бронювання альтанки', $menu);
        $this->assertStringContainsString('💸 Звіт боржників', $menu);
        $this->assertStringContainsString('🔐 Вхід у панель', $menu);
    }

    public function testTheGuardSlashMenuIsStartAndPanelOnly(): void
    {
        $this->assertSame(
            [
                ['command' => 'start', 'description' => '🏠 Головне меню'],
                ['command' => 'panel', 'description' => '🔐 Вхід у панель'],
            ],
            BotMenuUpdateCommand::guardCommands(),
        );
    }
}
