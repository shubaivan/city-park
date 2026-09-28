<?php

namespace App\Tests\Telegram;

use App\Telegram\Start\Command\StartCommand;
use PHPUnit\Framework\TestCase;

/**
 * Усе про альтанку — одна кнопка головного меню і один екран (28.09.2026). Кнопка без
 * обробника не падає, а просто крутиться, тож маршрут перевіряється тут.
 */
class PavilionMenuTest extends TestCase
{
    public function testTheSubmenuButtonIsRouted(): void
    {
        $config = file_get_contents(__DIR__ . '/../../config/telegram.php');

        self::assertStringContainsString('StartCommand::PAVILION_MENU_CALLBACK', $config);
        self::assertStringContainsString('StartCommand::pavilionMenu($bot)', $config);
    }

    public function testBookingButtonsLiveInTheSubmenuNotOnTheMainMenu(): void
    {
        $src = file_get_contents((new \ReflectionClass(StartCommand::class))->getFileName());
        $main = substr($src, strpos($src, 'function mainMenuMarkup('));
        $sub = substr($src, strpos($src, 'function pavilionMenu('), strpos($src, 'function mainMenuMarkup(') - strpos($src, 'function pavilionMenu('));

        foreach (["'schedule-pavilion'", "'own-schedule'", "'booking-history'", "'photo-upload-info'"] as $cb) {
            self::assertStringContainsString($cb, $sub);
            self::assertStringNotContainsString($cb, $main);
        }
        self::assertStringContainsString("'type:route'", $main);
    }
}
