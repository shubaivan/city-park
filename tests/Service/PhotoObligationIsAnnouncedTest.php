<?php

namespace App\Tests\Service;

use App\Service\PavilionPhotoService;
use PHPUnit\Framework\TestCase;

/**
 * The photo obligation has to be stated before it is enforced.
 *
 * It was enforced for months and announced nowhere: a resident booked, sat in the
 * альтанка, went home, and an hour later found booking closed over a photo nobody had
 * ever mentioned. Аліна, 21.09.2026 — «Бо дістали, ніхто про це не знає» — and every one
 * of those people rings her, because the bot never gave them the rule.
 *
 * Three places, each answering a different moment: the confirm screen (before agreeing),
 * the confirmation itself (the message people scroll back to) and the «закінчується о»
 * reminder (the last moment the photo is easy to take, while they are still there).
 */
class PhotoObligationIsAnnouncedTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function screens(): array
    {
        return [
            'the confirm screen, read before «Підтверджую»' => [
                'src/Telegram/SchedulePavilion/Command/SchedulePavilion.php',
            ],
            'the reminder that the booking is ending' => [
                'src/Command/RemindCommand.php',
            ],
        ];
    }

    /** @dataProvider screens */
    public function testEveryBookingScreenStatesTheObligation(string $file): void
    {
        $source = file_get_contents(__DIR__ . '/../../' . $file);

        $this->assertStringContainsString(
            'PavilionPhotoService::obligation',
            $source,
            $file . ' must state the photo obligation, and state it from the service so the '
            . 'copy cannot disagree with the cron that enforces it',
        );
    }

    /**
     * The deadline in the copy is read from the constant, never retyped.
     *
     * BLOCK_AFTER_MIN is what the cron actually acts on; a sentence that announces a rule
     * must not be able to disagree with the rule.
     */
    public function testTheAnnouncedDeadlineFollowsTheConstant(): void
    {
        $this->assertStringContainsString('години', PavilionPhotoService::obligationNotice());
        $this->assertSame(60, PavilionPhotoService::BLOCK_AFTER_MIN);
        $this->assertSame('година', PavilionPhotoService::blockAfterDuration());
        $this->assertSame('протягом години', PavilionPhotoService::blockAfterLabel());
    }

    /**
     * The spelled-out version has to carry the three facts somebody needs to comply:
     * what to send, where to send it, and what happens if they do not.
     */
    public function testTheConfirmationSpellsOutWhatToDo(): void
    {
        $block = PavilionPhotoService::obligationBlock();

        $this->assertStringContainsString('фото', $block);
        $this->assertStringContainsString('чат', $block, 'a photo goes to this chat — people were looking for a button');
        $this->assertStringContainsString(PavilionPhotoService::uploadGraceLabel(), $block);
    }
}
