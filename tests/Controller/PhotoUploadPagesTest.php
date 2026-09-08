<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The three tokenised photo-upload pages must not drift apart.
 *
 * There are three of them — complaints, rental listings, service offers — and they are
 * near-twins by copy rather than by inheritance. That is a deliberate call (each is a
 * standalone page opened inside Telegram on a phone, with no Encore and no shared layout),
 * and it comes with exactly the failure this file exists to catch: on 08.09.2026 the
 * complaints page guarded against pressing «Готово» mid-upload, the rental page — the
 * original the other two were copied from — still did not, and had been quietly losing
 * pictures ever since. The same lesson `ImageStore` was extracted for.
 *
 * Only the rules that cost a resident their photo are pinned here. The copy, the colours
 * and the headings are each page's own.
 */
class PhotoUploadPagesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        $root = __DIR__ . '/../../templates';

        return [
            'complaints' => [$root . '/complaint/photo_upload.html.twig'],
            'rental listings' => [$root . '/rental/photo_upload.html.twig'],
            'service offers' => [$root . '/service/photo_upload.html.twig'],
        ];
    }

    /**
     * «Готово» closes the Web App. Leaving it tappable while an upload is in flight loses
     * the picture — and on a phone an upload is seconds of nothing happening, which is
     * exactly when somebody presses it.
     *
     * @dataProvider pages
     */
    public function testTheDoneButtonIsHiddenWhileAnUploadIsInFlight(string $file): void
    {
        $source = (string)file_get_contents($file);
        $page = basename(dirname($file));

        $this->assertStringContainsString(
            'function busy(on)',
            $source,
            $page . ': no busy() — «Готово» stays tappable mid-upload and eats the photo',
        );
        $this->assertStringContainsString(
            "doneBtn.style.display = on ? 'none' : ''",
            $source,
            $page . ': busy() must hide the button, not merely grey it out',
        );
        $this->assertStringContainsString(
            'busy(true)',
            $source,
            $page . ': nothing ever enters the busy state',
        );
    }

    /**
     * And it must come back. A chain that ended in an error leaving the page permanently
     * mid-upload is worse than the bug it replaced: the resident has no way out but to
     * close the page, which is what loses the photo in the first place.
     *
     * @dataProvider pages
     */
    public function testTheBusyStateIsClearedOnBothPaths(string $file): void
    {
        $source = (string)file_get_contents($file);
        $page = basename(dirname($file));

        $this->assertStringContainsString(
            ".then(function () { busy(false); })",
            $source,
            $page . ': the success path never restores «Готово»',
        );
        $this->assertStringContainsString(
            'catch(function () { busy(false);',
            $source,
            $page . ': a failed upload leaves the page stuck with no «Готово»',
        );
    }

    /**
     * The waiting line has to read as "wait", not as decoration — red and spinning, the
     * shape the complaints page arrived at first.
     *
     * @dataProvider pages
     */
    public function testTheWaitingLineIsUnmistakable(string $file): void
    {
        $source = (string)file_get_contents($file);
        $page = basename(dirname($file));

        $this->assertStringContainsString('.msg.busy', $source, $page . ': no busy style at all');
        $this->assertStringContainsString(
            'animation: spin',
            $source,
            $page . ': a static line reads as a label, not as "something is happening"',
        );
    }

    /**
     * Every one of these pages is authorised by its token and nothing else, so none of them
     * may be indexed or cached by a search engine that follows a forwarded link.
     *
     * @dataProvider pages
     */
    public function testNoneOfThemInvitesIndexing(string $file): void
    {
        $this->assertStringContainsString(
            'name="robots" content="noindex, nofollow"',
            (string)file_get_contents($file),
            basename(dirname($file)) . ': a tokenised page must not be indexable',
        );
    }
}
