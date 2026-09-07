<?php

namespace App\Tests\Telegram;

use PHPUnit\Framework\TestCase;

/**
 * Every `rent:` button the bot draws must be routed by the patterns in config/telegram.php.
 *
 * An unrouted callback errors nowhere: Telegram shows the button spinning and then gives
 * up, which from the resident's side is indistinguishable from the bot being down. The
 * complaints menu has the same guard for the same reason; this one was added when the
 * rent/sale tabs introduced a new callback shape (`rent:deal:<kind>:<page>`) and the
 * pagination stopped emitting the old one.
 */
class RentalCallbackWiringTest extends TestCase
{
    /** @return string[] */
    private function patterns(): array
    {
        $config = (string)file_get_contents(__DIR__ . '/../../config/telegram.php');

        preg_match_all("/onCallbackQueryData\(\s*'(\^rent:[^']+)'/", $config, $matches);
        $patterns = $matches[1];

        // The publish conversation is wired by its constants rather than a literal regex.
        $publish = (string)file_get_contents(__DIR__ . '/../../src/Telegram/Rental/Command/RentalPublish.php');
        preg_match_all("/public const START\w* = '([^']+)'/", $publish, $consts);

        foreach ($consts[1] as $literal) {
            $patterns[] = '^' . preg_quote($literal, '/') . '$';
        }

        $patterns[] = '^rental-menu$';

        return $patterns;
    }

    /** @return string[] */
    private function emittedCallbacks(): array
    {
        $dir = __DIR__ . '/../../src/Telegram/Rental/Command';
        $found = [];

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);

            // 'rent:view:' . $id  →  rent:view:1 ; 'rent:deal:' . $deal . ':' . $page → rent:deal:all:1
            preg_match_all("/'(rent:[a-z:]*)'(\s*\.\s*[^,)]+)?/", $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $literal = $match[1];
                $tail = $match[2] ?? '';

                // A literal ending in ':' is a prefix — both the dispatcher's
                // str_starts_with() and a button built by concatenation — so it is
                // completed into the shape that button actually sends.
                $sample = match (true) {
                    str_starts_with($literal, 'rent:deal:') => 'rent:deal:all:1',
                    str_starts_with($literal, 'rent:pic:') => 'rent:pic:1:0',
                    str_ends_with($literal, ':') => $literal . '1',
                    default => $literal,
                };

                $found[$sample] = true;
            }
        }

        return array_keys($found);
    }

    public function testEveryRentCallbackIsRouted(): void
    {
        $patterns = $this->patterns();
        $this->assertNotEmpty($patterns, 'no rent patterns found in config/telegram.php');

        $unrouted = [];

        foreach ($this->emittedCallbacks() as $callback) {
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
