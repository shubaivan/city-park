<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Telegram\CarPlate\Command\CarPlateCheck;
use App\Telegram\CarPlate\Command\CarPlateCommand;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «🚗 Мої авто» must be reachable: every `car:` button routed, and the menu button drawn.
 *
 * Both failures are silent here. An unrouted callback spins and gives up, and a button the
 * menu does not draw is indistinguishable from «ще не задеплоїли» — which is how the guard
 * buttons shipped invisible on 07.09.2026. The Viber post tells the house to look for this
 * button, so it has to be there.
 */
class CarPlateWiringTest extends KernelTestCase
{
    /** @return string[] */
    private function patterns(): array
    {
        $config = (string)file_get_contents(__DIR__ . '/../../config/telegram.php');

        preg_match_all("/onCallbackQueryData\(\s*'(\^car:[^']+)'/", $config, $matches);
        $patterns = $matches[1];

        foreach (glob(__DIR__ . '/../../src/Telegram/CarPlate/Command/*.php') ?: [] as $file) {
            $class = 'App\\Telegram\\CarPlate\\Command\\' . basename($file, '.php');

            preg_match_all(
                "/public const (MENU_CALLBACK|START_CALLBACK) = '([^']+)'/",
                (string)file_get_contents($file),
                $consts,
                PREG_SET_ORDER,
            );

            foreach ($consts as [, $name, $literal]) {
                // Only constants the config actually wires count as routes.
                if (str_contains($config, '\\' . $class . '::' . $name)) {
                    $patterns[] = '^' . preg_quote($literal, '/') . '$';
                }
            }
        }

        return $patterns;
    }

    public function testEveryCarCallbackIsRouted(): void
    {
        $patterns = $this->patterns();
        $this->assertNotEmpty($patterns);

        $emitted = [];

        foreach (glob(__DIR__ . '/../../src/Telegram/CarPlate/Command/*.php') ?: [] as $file) {
            preg_match_all("/'(car:[a-z:]*)'/", (string)file_get_contents($file), $matches);

            foreach ($matches[1] as $literal) {
                $emitted[] = str_ends_with($literal, ':') ? $literal . '1' : $literal;
            }
        }

        $this->assertNotEmpty($emitted);

        $unrouted = array_filter($emitted, static function (string $callback) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/', $callback) === 1) {
                    return false;
                }
            }

            return true;
        });

        $this->assertSame([], array_values($unrouted), 'these buttons reach no handler — they just spin');
    }

    /** @return string[] "text -> callback" */
    private function menu(?Account $account): array
    {
        self::bootKernel();

        $markup = new \ReflectionMethod(StartCommand::class, 'mainMenuMarkup');
        $buttons = [];

        foreach ($markup->invoke(null, self::getContainer()->get(Nutgram::class), $account)->inline_keyboard as $row) {
            foreach ($row as $button) {
                $buttons[] = $button->text . ' -> ' . ($button->callback_data ?? '?');
            }
        }

        return $buttons;
    }

    public function testAConfirmedResidentGetsTheButton(): void
    {
        $account = (new Account())
            ->setAccountNumber('8-8-0-002')
            ->setApartmentNumber('2')
            ->setHouseNumber('21')
            ->setStreet('Козацька');

        $this->assertContains('🚗 Мої авто -> ' . CarPlateCommand::MENU_CALLBACK, $this->menu($account));
    }

    public function testAnUnlinkedVisitorDoesNot(): void
    {
        $menu = $this->menu(null);

        $this->assertNotContains('🚗 Мої авто -> ' . CarPlateCommand::MENU_CALLBACK, $menu);
        // Nor the guard's lookup: nobody in the test environment is a guard.
        $this->assertNotContains('🚗 Перевірити номер авто -> ' . CarPlateCheck::START_CALLBACK, $menu);
    }
}
