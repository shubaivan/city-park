<?php

namespace App\Tests\Telegram;

use App\Telegram\Guard\Command\GuardScanCommand;
use App\Telegram\ServiceOffer\Command\ServiceMenuCommand;
use App\Telegram\Start\Command\StartPayloadCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Where each deep link ends up.
 *
 * `start {payload}` used to be wired straight to the guard's QR handler, because the QR
 * was the only deep link there was. Adding a second one (a service advert, linked from the
 * residents' chat) put a router in front — and a router is a thing that can silently send
 * a scanned QR to the wrong place, or nowhere.
 *
 * `GuardWiringTest` only asserts that `onCommand('start {payload}'` appears in the config,
 * which stayed true through that change and would stay true through a broken router. This
 * pins what actually has to hold: which handler each prefix reaches.
 */
class StartPayloadRoutingTest extends KernelTestCase
{
    public function testTheGuardsQrStillReachesTheScanner(): void
    {
        $guard = $this->createMock(GuardScanCommand::class);
        $guard->expects($this->once())->method('__invoke')
            ->with($this->anything(), 'g-7-abcdef012345');

        $services = $this->createMock(ServiceMenuCommand::class);
        $services->expects($this->never())->method('openFromDeepLink');

        (new StartPayloadCommand($guard, $services))($this->bot(), 'g-7-abcdef012345');
    }

    public function testAServiceLinkOpensThatOffersCard(): void
    {
        $guard = $this->createMock(GuardScanCommand::class);
        $guard->expects($this->never())->method('__invoke');

        $services = $this->createMock(ServiceMenuCommand::class);
        $services->expects($this->once())->method('openFromDeepLink')
            ->with($this->anything(), 42);

        (new StartPayloadCommand($guard, $services))($this->bot(), 's-42');
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

        (new StartPayloadCommand($guard, $services))($this->bot(), 'utm_source=flyer');
    }

    /** The prefixes are constants because the link is built in one file and read in another. */
    public function testTheServicePrefixIsWhatTheChatPostBuilds(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/ServiceOfferService.php');

        $this->assertStringContainsString(
            'StartPayloadCommand::SERVICE_PREFIX',
            $source,
            'the chat post must build its link from the same constant the router reads',
        );
    }

    private function bot(): Nutgram
    {
        self::bootKernel();

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());

        return $bot;
    }
}
