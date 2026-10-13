<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Service\OsbbContacts;
use App\Telegram\Start\Command\StartCommand;
use PHPUnit\Framework\TestCase;

/**
 * Про додаток McLaut «Ворота» бот мовчав, і мешканці дізнавались про нього від McLaut.
 * Рядок стоїть під контактами ОСББ для прив'язаного мешканця і не показується незнайомцю.
 */
class GatesNoticeOnMenuTest extends TestCase
{
    public function testLinkedResidentIsToldAboutTheGatesApp(): void
    {
        $block = $this->chairBlock(new Account());

        self::assertStringContainsString(StartCommand::GATES_NOTICE, $block);
        self::assertStringContainsString('McLaut', $block);
    }

    /** «Який телефон охорони?» — the most-asked number, so it sits with the others. */
    public function testLinkedResidentIsGivenTheGuardPhone(): void
    {
        $block = $this->chairBlock(new Account());

        self::assertStringContainsString(OsbbContacts::GUARD_LINE, $block);
        self::assertStringContainsString('href="tel:+380683694758"', $block);
    }

    public function testUnlinkedVisitorSeesNothing(): void
    {
        self::assertSame('', $this->chairBlock(null));
    }

    private function chairBlock(?Account $account): string
    {
        $m = new \ReflectionMethod(StartCommand::class, 'chairBlock');

        return $m->invoke(null, $account);
    }
}
