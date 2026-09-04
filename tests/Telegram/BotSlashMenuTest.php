<?php

namespace App\Tests\Telegram;

use App\Command\BotMenuUpdateCommand;
use PHPUnit\Framework\TestCase;

/**
 * The slash menu is a vertical list of ten identical-looking lines, and the icon is what
 * a thumb aims at. `/start` was the one entry without one — the row for «Головне меню»
 * read as a gap in the list rather than an item in it.
 *
 * The 32-character cap is Telegram's: a longer description is rejected outright, and the
 * command is pushed by hand (`bot:menu:update`), so the failure would surface as "the menu
 * did not change" long after the deploy that caused it.
 */
class BotSlashMenuTest extends TestCase
{
    /** @return array<int, array{0: string, 1: string}> */
    private function menu(): array
    {
        return (new \ReflectionClassConstant(BotMenuUpdateCommand::class, 'MENU'))->getValue();
    }

    public function testEveryEntryLeadsWithAnIcon(): void
    {
        foreach ($this->menu() as [$command, $description]) {
            // Matched against the alphabets the menu is actually written in rather than
            // \p{L}: PCRE classifies ℹ️ (U+2139, in the Letterlike Symbols block) as a
            // letter, so the general rule failed the one entry that does have an icon.
            $this->assertDoesNotMatchRegularExpression(
                '/^[A-Za-zА-Яа-яЇЄІҐїєіґ0-9\s]/u',
                $description,
                sprintf('/%s has no icon in front of its label', $command),
            );
        }
    }

    public function testNoDescriptionExceedsTelegramsLimit(): void
    {
        foreach ($this->menu() as [$command, $description]) {
            $this->assertLessThanOrEqual(
                32,
                mb_strlen($description),
                sprintf('/%s would be rejected by setMyCommands', $command),
            );
        }
    }
}
