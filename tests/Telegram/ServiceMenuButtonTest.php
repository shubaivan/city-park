<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The «🛠 Послуги» button must actually be drawn — and only for a confirmed resident.
 *
 * This is the failure that has already happened once: both guard buttons shipped on
 * 07.09.2026 and simply did not appear. Nothing errored, nothing reached a log, and from
 * the outside it was indistinguishable from «ще не задеплоїли». MenuContainerLookupsTest
 * pins the usual cause (a private service inlined away); this pins the symptom, which is
 * the thing anybody would actually notice.
 *
 * Deliberately database-free: every helper the menu calls is wrapped in
 * `catch (\Throwable) { … }`, so the label degrades to its countless form without a
 * connection and the row is still there. That is the same degradation prod would show if
 * the query failed, which makes it worth asserting rather than working around.
 */
class ServiceMenuButtonTest extends KernelTestCase
{
    /** @return string[] "text -> callback" for every button on the menu */
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

    public function testAConfirmedResidentGetsTheServicesButton(): void
    {
        $menu = $this->menu($this->account());

        $services = array_values(array_filter(
            $menu,
            static fn (string $b): bool => str_ends_with($b, '-> services-menu'),
        ));

        $this->assertCount(1, $services, "the services button is missing from the menu:\n" . implode("\n", $menu));
        $this->assertStringStartsWith('🛠', $services[0], 'the icon is what a thumb aims at');
    }

    /**
     * Every offer names the flat its author lives in, which is most of why a neighbour
     * trusts it — and exactly why somebody the ОСББ has not confirmed is not shown the
     * list. The opposite call to the rental board, and on purpose.
     */
    public function testAnUnlinkedVisitorDoesNotGetIt(): void
    {
        $this->assertSame(
            [],
            array_filter(
                $this->menu(null),
                static fn (string $b): bool => str_ends_with($b, '-> services-menu'),
            ),
        );
    }

    /** The button sits directly under 🔑 Оренда — the two noticeboards belong together. */
    public function testItSitsRightUnderTheRentalBoard(): void
    {
        $menu = $this->menu($this->account());

        $rental = array_search('🔑 Оренда та продаж -> rental-menu', $menu, true);
        $services = array_search(true, array_map(
            static fn (string $b): bool => str_ends_with($b, '-> services-menu'),
            $menu,
        ), true);

        $this->assertNotFalse($rental);
        $this->assertSame($rental + 1, $services);
    }
}
