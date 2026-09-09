<?php

namespace App\Tests\Telegram;

use PHPUnit\Framework\TestCase;

/**
 * Named arguments to the Telegram API must be the types Nutgram declares.
 *
 * These fail **only when the line runs**. `php -l` passes, the container lints, the suite
 * goes green, and the button looks finished — then somebody presses it, `/hook` answers
 * 500, and Telegram retries the same tap until the callback expires. From the outside that
 * is «жму і нічого», with nothing on screen to say what happened.
 *
 * It cost one on 09.09.2026: «🔗 Те саме текстом» passed `['is_disabled' => true]` where
 * `sendMessage()` type-hints `?LinkPreviewOptions`. Every other board in this codebase was
 * already writing `LinkPreviewOptions::make(...)` two lines away — which is what makes this
 * check worth having: the right shape exists, it is simply easy to type the other one.
 *
 * The same family as `VoteTypeHintsResolveTest` (an unimported hint that took down every
 * ballot) and `ImportsResolveTest` (a `use` that named nothing).
 */
class ApiArgumentShapesTest extends TestCase
{
    /** argument => the prefix its value must start with. */
    private const SHAPES = [
        'link_preview_options' => 'LinkPreviewOptions::',
    ];

    /** @return iterable<string, array{string, string, string}> */
    public static function occurrences(): iterable
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());
            $short = substr($file->getPathname(), strlen($root) + 1);

            foreach (self::SHAPES as $argument => $expected) {
                preg_match_all('/' . $argument . ':\s*([^\n,]+)/', $source, $m);

                foreach ($m[1] as $i => $value) {
                    yield sprintf('%s #%d %s', $short, $i + 1, $argument) => [$short, trim($value), $expected];
                }
            }
        }
    }

    /** @dataProvider occurrences */
    public function testTheArgumentIsTheTypeNutgramDeclares(string $file, string $value, string $expected): void
    {
        // A variable is somebody else's problem — this catches literals written by hand.
        if (str_starts_with($value, '$')) {
            $this->assertTrue(true);

            return;
        }

        $this->assertStringStartsWith(
            $expected,
            $value,
            sprintf('%s passes %s — Nutgram type-hints this, so it throws when the line runs', $file, $value),
        );
    }
}
