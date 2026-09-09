<?php

namespace App\Tests\Telegram;

use PHPUnit\Framework\TestCase;

/**
 * Every `pass:` button the bot draws must be routed by the patterns in config/telegram.php.
 *
 * An unrouted callback errors nowhere: the button spins and gives up, which from the
 * resident's side is indistinguishable from the bot being down. Every other board in this
 * bot carries the same guard, and each of them earned it.
 */
class GuestPassWiringTest extends TestCase
{
    /** @return string[] */
    private function patterns(): array
    {
        $config = (string)file_get_contents(__DIR__ . '/../../config/telegram.php');

        preg_match_all("/onCallbackQueryData\(\s*'(\^pass:[^']+)'/", $config, $matches);
        $patterns = $matches[1];

        // Two are wired by their constants rather than a literal regex.
        foreach ([
            __DIR__ . '/../../src/Telegram/GuestPass/Command/GuestPassCommand.php',
            __DIR__ . '/../../src/Telegram/GuestPass/Command/GuestPassCreate.php',
        ] as $file) {
            preg_match_all(
                "/public const (?:MENU_CALLBACK|START_CALLBACK) = '([^']+)'/",
                (string)file_get_contents($file),
                $consts,
            );

            foreach ($consts[1] as $literal) {
                $patterns[] = '^' . preg_quote($literal, '/') . '$';
            }
        }

        return $patterns;
    }

    /** @return string[] */
    private function emitted(): array
    {
        $found = [];

        foreach (glob(__DIR__ . '/../../src/Telegram/GuestPass/Command/*.php') ?: [] as $file) {
            preg_match_all("/'(pass:[a-z:]*)'/", (string)file_get_contents($file), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $literal = $match[1];

                // A literal ending in ':' is a prefix completed with an id at runtime.
                $found[str_ends_with($literal, ':') ? $literal . '1' : $literal] = true;
            }
        }

        return array_keys($found);
    }

    public function testEveryGuestPassCallbackIsRouted(): void
    {
        $patterns = $this->patterns();
        $this->assertNotEmpty($patterns, 'no pass: patterns found in config/telegram.php');

        $emitted = $this->emitted();
        $this->assertNotEmpty($emitted, 'no pass: buttons found — did the handlers change shape?');

        $unrouted = [];

        foreach ($emitted as $callback) {
            $routed = false;

            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/', $callback) === 1) {
                    $routed = true;
                    break;
                }
            }

            $routed or $unrouted[] = $callback;
        }

        $this->assertSame([], $unrouted, 'these buttons reach no handler — they just spin');
    }

}
