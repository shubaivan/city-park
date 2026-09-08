<?php

namespace App\Tests\Telegram;

use PHPUnit\Framework\TestCase;

/**
 * Every `svc:` button the bot draws must be routed by the patterns in config/telegram.php.
 *
 * An unrouted callback errors nowhere: Telegram shows the button spinning and then gives
 * up, which from the resident's side is indistinguishable from the bot being down. The
 * rental and complaints menus carry the same guard for the same reason.
 */
class ServiceCallbackWiringTest extends TestCase
{
    /** @return string[] */
    private function patterns(): array
    {
        $config = (string)file_get_contents(__DIR__ . '/../../config/telegram.php');

        preg_match_all("/onCallbackQueryData\(\s*'(\^svc:[^']+)'/", $config, $matches);
        $patterns = $matches[1];

        // The publish conversation is wired by its constant rather than a literal regex.
        $publish = (string)file_get_contents(
            __DIR__ . '/../../src/Telegram/ServiceOffer/Command/ServicePublish.php'
        );
        preg_match_all("/public const START\w* = '([^']+)'/", $publish, $consts);

        foreach ($consts[1] as $literal) {
            $patterns[] = '^' . preg_quote($literal, '/') . '$';
        }

        $patterns[] = '^services-menu$';

        return $patterns;
    }

    /** @return string[] */
    private function emittedCallbacks(): array
    {
        $found = [];

        $files = array_merge(
            glob(__DIR__ . '/../../src/Telegram/ServiceOffer/Command/*.php') ?: [],
            // The service also builds buttons of its own — the renew prompt and the
            // "photos updated" card are sent from there, not from a handler.
            [__DIR__ . '/../../src/Service/ServiceOfferService.php'],
        );

        foreach ($files as $file) {
            $source = (string)file_get_contents($file);

            // 'svc:view:' . $id → svc:view:1 ; sprintf('svc:pic:%d:%d', …) → svc:pic:1:0
            preg_match_all("/'(svc:[a-z:]*)(?:%d:%d)?'/", $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $literal = $match[1];

                // A literal ending in ':' is a prefix — both the dispatcher's
                // str_starts_with() and a button built by concatenation — so it is
                // completed into the shape that button actually sends.
                $sample = match (true) {
                    str_starts_with($literal, 'svc:pic:') => 'svc:pic:1:0',
                    str_ends_with($literal, ':') => $literal . '1',
                    default => $literal,
                };

                $found[$sample] = true;
            }
        }

        return array_keys($found);
    }

    public function testEveryServiceCallbackIsRouted(): void
    {
        $patterns = $this->patterns();
        $this->assertNotEmpty($patterns, 'no svc patterns found in config/telegram.php');

        $emitted = $this->emittedCallbacks();
        $this->assertNotEmpty($emitted, 'no svc: buttons found — did the handlers change shape?');

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
