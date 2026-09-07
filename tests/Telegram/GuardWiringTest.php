<?php

namespace App\Tests\Telegram;

use PHPUnit\Framework\TestCase;
use SergiX44\Container\Container;
use SergiX44\Nutgram\Handlers\Type\Command;

/**
 * The guard's two buttons and its one deep link, checked against the config.
 */
class GuardWiringTest extends TestCase
{
    private function config(): string
    {
        return file_get_contents(__DIR__ . '/../../config/telegram.php');
    }

    /**
     * The one that would be expensive to get wrong: `/start` is how 457 people open this
     * bot, and the QR handler is registered on the same word.
     *
     * Nutgram anchors command patterns (`^…$`), so `start {payload}` needs the space and a
     * payload — a bare `/start` still falls through to StartCommand. Pinned because the
     * failure mode is the whole house losing the main menu, and it would show up as
     * «бот не відповідає», not as an error anywhere.
     */
    public function testTheDeepLinkDoesNotSwallowAPlainStart(): void
    {
        $container = new Container();
        $plain = new Command(static fn() => null, 'start');
        $deep = new Command(static fn() => null, 'start {payload}');

        $this->assertTrue($plain->matching('/start', $container));
        $this->assertFalse($deep->matching('/start', $container));

        $this->assertFalse($plain->matching('/start g-7-abcdef012345', $container));
        $this->assertTrue($deep->matching('/start g-7-abcdef012345', $container));
    }

    public function testTheGuardHandlersAreRegistered(): void
    {
        $config = $this->config();

        $this->assertStringContainsString(
            "onCommand('start {payload}'",
            $config,
            'the QR deep link has no handler, so a scan does nothing at all',
        );
        $this->assertStringContainsString('GuardCommand::MENU_CALLBACK', $config);
        $this->assertStringContainsString('GuardQrCommand::MENU_CALLBACK', $config);
        $this->assertStringContainsString("onCommand('guard'", $config);
    }

    /**
     * The QR is actually rendered.
     *
     * endroid/qr-code 6 dropped the `Builder::create()` entry point that 5 had, and the
     * first shape of this feature called it: nothing in the suite touched that line, so it
     * shipped and died on prod with «Call to undefined method». A picture nobody renders
     * in a test is a picture nobody renders.
     */
    public function testTheQrCodeIsActuallyRendered(): void
    {
        $png = \App\Telegram\Guard\Command\GuardQrCommand::render(
            'https://t.me/che_city_park_bot?start=g-7-abcdef012345',
        );

        $this->assertNotSame('', $png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    /**
     * And it is wrapped the way Telegram wants it — a stream, not the bytes.
     *
     * `InputFile::make()` on a raw string throws «Invalid resource specified», which is a
     * 500 on /hook; Telegram then retries the same tap until the callback query is too old
     * to answer, and the person sees a button that does nothing at all. Shipped that way
     * on 07.09.2026 because the test above proved the PNG existed and nothing proved it
     * could be sent.
     */
    public function testTheQrIsWrappedAsSomethingTelegramCanSend(): void
    {
        $photo = \App\Telegram\Guard\Command\GuardQrCommand::photo(
            'https://t.me/che_city_park_bot?start=g-7-abcdef012345',
        );

        $this->assertInstanceOf(\SergiX44\Nutgram\Telegram\Types\Internal\InputFile::class, $photo);
    }

    /**
     * `/guard` must stay out of the slash menu, which is pushed to every private chat.
     * There it would be a command 457 residents can type and nobody but two people may
     * use — and each refusal is a message that reads like the bot is broken.
     */
    public function testGuardIsNotInThePushedSlashMenu(): void
    {
        $menu = file_get_contents(__DIR__ . '/../../src/Command/BotMenuUpdateCommand.php');

        $this->assertStringNotContainsString("'guard'", $menu);
        $this->assertStringNotContainsString('"guard"', $menu);
    }
}
