<?php

namespace App\Tests\Telegram;

use App\Service\DeepLink;
use App\Service\TelegramUserService;
use App\Telegram\Complaint\Command\ComplaintMenuCommand;
use App\Telegram\Debt\Command\DebtBoardCommand;
use App\Telegram\Guard\Command\GuardScanCommand;
use App\Telegram\Rental\Command\RentalMenuCommand;
use App\Telegram\ServiceOffer\Command\ServiceMenuCommand;
use App\Telegram\Start\Command\StartPayloadCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Where each deep link ends up.
 *
 * `start {payload}` used to be wired straight to the guard's QR handler, because the QR was
 * the only deep link there was. Every board now posts one, so the payload goes through a
 * router — and a router is a thing that can silently send a scanned QR to the wrong place,
 * or nowhere.
 *
 * `GuardWiringTest` only asserts that `onCommand('start {payload}'` appears in the config,
 * which stayed true through that change and would stay true through a broken router. This
 * pins what actually has to hold: which handler each prefix reaches, and that every prefix
 * the links are *built* from is one the router *reads*.
 */
class StartPayloadRoutingTest extends KernelTestCase
{
    private function command(
        ?GuardScanCommand $guard = null,
        ?ServiceMenuCommand $services = null,
        ?RentalMenuCommand $rentals = null,
        ?ComplaintMenuCommand $complaints = null,
        ?DebtBoardCommand $debts = null,
    ): StartPayloadCommand {
        return new StartPayloadCommand(
            $guard ?? $this->createMock(GuardScanCommand::class),
            $services ?? $this->createMock(ServiceMenuCommand::class),
            $rentals ?? $this->createMock(RentalMenuCommand::class),
            $complaints ?? $this->createMock(ComplaintMenuCommand::class),
            $debts ?? $this->createMock(DebtBoardCommand::class),
            $this->createMock(DeepLink::class),
            $this->createMock(TelegramUserService::class),
        );
    }

    public function testTheGuardsQrStillReachesTheScanner(): void
    {
        $guard = $this->createMock(GuardScanCommand::class);
        $guard->expects($this->once())->method('__invoke')
            ->with($this->anything(), 'g-7-abcdef012345');

        $this->command(guard: $guard)($this->bot(), 'g-7-abcdef012345');
    }

    public function testAServiceLinkOpensThatOffersCard(): void
    {
        $services = $this->createMock(ServiceMenuCommand::class);
        $services->expects($this->once())->method('openFromDeepLink')->with($this->anything(), 42);

        $this->command(services: $services)($this->bot(), 's-42');
    }

    public function testARentalLinkOpensThatListingsCard(): void
    {
        $rentals = $this->createMock(RentalMenuCommand::class);
        $rentals->expects($this->once())->method('openFromDeepLink')->with($this->anything(), 7);

        $this->command(rentals: $rentals)($this->bot(), 'r-7');
    }

    public function testAComplaintLinkOpensThatComplaintsCard(): void
    {
        $complaints = $this->createMock(ComplaintMenuCommand::class);
        $complaints->expects($this->once())->method('openFromDeepLink')->with($this->anything(), 11);

        $this->command(complaints: $complaints)($this->bot(), 'c-11');
    }

    public function testADebtLinkOpensTheBoard(): void
    {
        $debts = $this->createMock(DebtBoardCommand::class);
        $debts->expects($this->once())->method('openFromDeepLink')->with($this->anything(), 3);

        $this->command(debts: $debts)($this->bot(), 'd-3');
    }

    /**
     * An unknown payload opens the main menu rather than erroring.
     *
     * A link may be forwarded months later from a post that has since been deleted, or
     * simply mistyped. Before the router, anything that was not a QR was answered «цей
     * QR-код зчитує охорона», which is a confusing thing to tell somebody who tapped an
     * advert.
     */
    public function testAnUnknownPayloadFallsThroughToTheMenu(): void
    {
        $guard = $this->createMock(GuardScanCommand::class);
        $guard->expects($this->never())->method('__invoke');

        $services = $this->createMock(ServiceMenuCommand::class);
        $services->expects($this->never())->method('openFromDeepLink');

        $this->command(guard: $guard, services: $services)($this->bot(), 'utm_source=flyer');
    }

    /** A tap is recorded before the card renders — a slow render must not lose the click. */
    public function testTheClickIsRecordedWithTheKindAndTheId(): void
    {
        $links = $this->createMock(DeepLink::class);
        $links->expects($this->once())->method('record')
            ->with(DeepLink::KIND_RENTAL, 7, $this->anything());

        (new StartPayloadCommand(
            $this->createMock(GuardScanCommand::class),
            $this->createMock(ServiceMenuCommand::class),
            $this->createMock(RentalMenuCommand::class),
            $this->createMock(ComplaintMenuCommand::class),
            $this->createMock(DebtBoardCommand::class),
            $links,
            $this->createMock(TelegramUserService::class),
        ))($this->bot(), 'r-7');
    }

    /**
     * The guard's scan is not recorded.
     *
     * It is a staff action on somebody else's booking, not a resident following an advert,
     * and this table is defensible precisely because of what it does not hold.
     */
    public function testTheGuardsScanIsNotRecordedAsAClick(): void
    {
        $links = $this->createMock(DeepLink::class);
        $links->expects($this->never())->method('record');

        (new StartPayloadCommand(
            $this->createMock(GuardScanCommand::class),
            $this->createMock(ServiceMenuCommand::class),
            $this->createMock(RentalMenuCommand::class),
            $this->createMock(ComplaintMenuCommand::class),
            $this->createMock(DebtBoardCommand::class),
            $links,
            $this->createMock(TelegramUserService::class),
        ))($this->bot(), 'g-7-abcdef012345');
    }

    /**
     * Every kind a link can be built for must be a kind the router reads.
     *
     * The prefixes live in one map next to the builder for this reason; the failure this
     * catches is a new board posting a button that lands nowhere, which shows up as a link
     * that opens the main menu and looks like the bot forgetting what you tapped.
     */
    public function testEveryKindTheLinksAreBuiltForIsRouted(): void
    {
        $source = (string)file_get_contents(
            __DIR__ . '/../../src/Telegram/Start/Command/StartPayloadCommand.php'
        );

        foreach (array_keys(DeepLink::PREFIXES) as $kind) {
            $const = 'KIND_' . strtoupper($kind);

            $this->assertStringContainsString(
                'DeepLink::' . $const,
                $source,
                sprintf('%s can be linked to but the router never mentions it', $kind),
            );
        }
    }

    private function bot(): Nutgram
    {
        self::bootKernel();

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());

        return $bot;
    }
}
