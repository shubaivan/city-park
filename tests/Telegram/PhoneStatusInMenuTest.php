<?php

namespace App\Tests\Telegram;

use App\Command\BotMenuUpdateCommand;
use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Telegram\ApprovePhone\Command\ApprovePhoneCommand;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «Де там PHONE не знайшла: киньте скріншот» — Helen G in the Viber group, 23.10.2026.
 *
 * `/phone` was the door to every resident feature and it was reachable only as a blue
 * word inside other messages: not in the slash menu, not in the main menu. This pins the
 * three places it now lives, and that a resident who has already shared their number is
 * told so instead of being asked again.
 */
class PhoneStatusInMenuTest extends KernelTestCase
{
    /** @return string[] "text -> callback" for every button on the menu, in order */
    private function menu(?Account $account): array
    {
        self::bootKernel();

        $bot = self::getContainer()->get(Nutgram::class);

        $markup = new \ReflectionMethod(StartCommand::class, 'mainMenuMarkup');
        $markup->setAccessible(true);

        $buttons = [];

        foreach ($markup->invoke(null, $bot, $account)->inline_keyboard as $row) {
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

    public function testSomebodyTheBotDoesNotKnowGetsTheShareButtonFirst(): void
    {
        $menu = $this->menu(null);

        $this->assertSame(
            '📱 Поділитися номером телефону -> ' . ApprovePhoneCommand::MENU_CALLBACK,
            $menu[0],
            "the one button that opens the rest of the menu must lead it:\n" . implode("\n", $menu),
        );
    }

    public function testAConfirmedResidentSeesTheCheckmarkAndIsNotAskedAgain(): void
    {
        $menu = $this->menu($this->account());

        $this->assertSame('✅ Телефон підтверджено -> ' . ApprovePhoneCommand::MENU_CALLBACK, end($menu));
        $this->assertNotContains('📱 Поділитися номером телефону -> ' . ApprovePhoneCommand::MENU_CALLBACK, $menu);
    }

    public function testTheStateFollowsTheAccountBeforeThePhone(): void
    {
        $withPhone = (new TelegramUser())->setPhoneNumber('380671234567');

        $this->assertSame(ApprovePhoneCommand::STATE_NONE, ApprovePhoneCommand::state(null, null));
        $this->assertSame(ApprovePhoneCommand::STATE_NONE, ApprovePhoneCommand::state(new TelegramUser(), null));
        $this->assertSame(ApprovePhoneCommand::STATE_UNKNOWN, ApprovePhoneCommand::state($withPhone, null));
        $this->assertSame(ApprovePhoneCommand::STATE_CONFIRMED, ApprovePhoneCommand::state($withPhone, $this->account()));

        // Linked by the accountant without ever sharing a number: inside already.
        $this->assertSame(ApprovePhoneCommand::STATE_CONFIRMED, ApprovePhoneCommand::state(new TelegramUser(), $this->account()));
    }

    public function testTheConfirmationNamesTheNumberAndTheFlat(): void
    {
        $text = ApprovePhoneCommand::confirmedText(
            (new TelegramUser())->setPhoneNumber('380671234567'),
            $this->account(),
        );

        $this->assertStringContainsString('+380671234567', $text);
        $this->assertStringContainsString('8-8-0-002', $text);
        $this->assertStringContainsString('кв. 2', $text);
    }

    public function testItIsInTheSlashMenu(): void
    {
        $menu = (new \ReflectionClassConstant(BotMenuUpdateCommand::class, 'MENU'))->getValue();

        $this->assertContains('phone', array_column($menu, 0));
    }

    public function testTheMenuButtonIsRouted(): void
    {
        $config = (string)file_get_contents(dirname(__DIR__, 2) . '/config/telegram.php');

        $this->assertStringContainsString(
            'onCallbackQueryData(\App\Telegram\ApprovePhone\Command\ApprovePhoneCommand::MENU_CALLBACK, \App\Telegram\ApprovePhone\Command\ApprovePhoneCommand::class)',
            $config,
            'an unrouted button does not error — it spins, which reads as the bot being down',
        );
    }
}
