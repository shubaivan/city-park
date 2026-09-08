<?php

namespace App\Tests\Telegram;

use PHPUnit\Framework\TestCase;

/**
 * Every board that posts into the chat must also be able to hand over a block that
 * survives leaving Telegram.
 *
 * The inline button is Telegram's alone — forwarded into Viber it is simply not there —
 * and the ЖК's Viber group still holds 653 people, which is where «хто дасть номер
 * майстра?» is still asked. So the button and the paste-ready text are two halves of the
 * same rule, and this pins the second half the way ResidentChatPostsLinkBackTest pins the
 * first.
 */
class ShareBlocksTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function boards(): array
    {
        $src = __DIR__ . '/../../src';

        return [
            'service offers' => [$src . '/Service/ServiceOfferService.php', $src . '/Telegram/ServiceOffer/Command/ServiceMenuCommand.php'],
            'rental listings' => [$src . '/Service/RentalListingService.php', $src . '/Telegram/Rental/Command/RentalMenuCommand.php'],
            'complaints' => [$src . '/Service/ComplaintService.php', $src . '/Telegram/Complaint/Command/ComplaintMenuCommand.php'],
        ];
    }

    /** @dataProvider boards */
    public function testEachBoardCanProduceAPasteReadyBlock(string $service, string $handler): void
    {
        $name = basename($service, '.php');

        $this->assertStringContainsString(
            'public function shareText(',
            (string)file_get_contents($service),
            $name . ': no shareText() — an advert cannot leave Telegram',
        );

        $this->assertStringContainsString(
            ':share:',
            (string)file_get_contents($handler),
            basename($handler, '.php') . ': the card offers no way to reach shareText()',
        );
    }

    /**
     * The block is plain. It is going to be selected with a thumb and dropped into another
     * app, where `<b>` and `**` arrive as punctuation rather than as emphasis.
     *
     * @dataProvider boards
     */
    public function testTheBlockCarriesNoMarkup(string $service, string $handler): void
    {
        $source = (string)file_get_contents($service);
        $start = strpos($source, 'public function shareText(');
        $body = substr($source, (int)$start, 2200);

        foreach (['<b>', '</b>', '<i>', '</i>', '```'] as $markup) {
            $this->assertStringNotContainsString(
                $markup,
                $body,
                basename($service, '.php') . ": shareText() must be plain — «{$markup}» travels with the copy",
            );
        }
    }

    /**
     * And it is sent with the preview switched off: the block ends in a t.me link, and
     * Telegram would otherwise stack a bot card underneath the very text somebody is
     * trying to select.
     *
     * @dataProvider boards
     */
    public function testTheShareMessageDisablesTheLinkPreview(string $service, string $handler): void
    {
        $this->assertStringContainsString(
            'LinkPreviewOptions::make(is_disabled: true)',
            (string)file_get_contents($handler),
            basename($handler, '.php') . ': the preview would push the text somebody is copying off screen',
        );
    }
}
