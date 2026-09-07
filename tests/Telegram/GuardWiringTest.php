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
