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

                // A literal ending in ':' is a prefix completed at runtime — with an id,
                // and for the hour windows with the length after it.
                $sample = match (true) {
                    str_starts_with($literal, 'pass:hrs:') => 'pass:hrs:1:2',
                    str_ends_with($literal, ':') => $literal . '1',
                    default => $literal,
                };

                $found[$sample] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The list button leads with the day the pass was issued.
     *
     * The rule every list of published things in this bot follows — «08.09 · Двері,
     * монтаж», «🆕 08.09 · Ліфт не працює» — and it earns its place here for the same
     * reason: a flat with three passes reads them as «this one is today, that one is from
     * the ремонт in June». Dates at a fixed width line up down the column; a trailing one
     * cannot, because the titles are ragged.
     */
    public function testTheListButtonLeadsWithTheIssueDate(): void
    {
        $source = (string)file_get_contents(
            __DIR__ . '/../../src/Telegram/GuestPass/Command/GuestPassCommand.php'
        );

        preg_match('/private static function buttonLabel.*?\n    \}/s', $source, $m);
        $this->assertNotEmpty($m, 'buttonLabel() is what draws the list');
        $this->assertStringContainsString("getCreatedAt()->format('d.m')", $m[0]);
        $this->assertMatchesRegularExpression(
            "/sprintf\('🟢 %s · %s/u",
            $m[0],
            'the date leads the label, before the trade',
        );
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
