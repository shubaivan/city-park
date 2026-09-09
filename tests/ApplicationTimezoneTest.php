<?php

namespace App\Tests;

use App\Entity\GuestPass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Stored moments are Kyiv wall-clock readings, so PHP has to read them in Kyiv.
 *
 * Doctrine writes `Y-m-d H:i:s` with no offset and rebuilds it in the default zone. With
 * the server's UTC default, `11:22` came back as 11:22 UTC — 14:22 Kyiv — and every
 * comparison against «now» was three hours late. On 09.09.2026 that was a builder's pass
 * reading «✅ Діє до 11:22» at 14:00: the guard's answer, three hours after the flat had
 * closed the window.
 *
 * The failure has no error and no log line — the arithmetic simply comes out wrong — so it
 * is exactly the kind that returns the day somebody "cleans up" the Kernel constructor.
 */
class ApplicationTimezoneTest extends KernelTestCase
{
    public function testTheApplicationRunsInKyiv(): void
    {
        self::bootKernel();

        $this->assertSame('Europe/Kyiv', date_default_timezone_get());
    }

    /** A pass whose window closed two hours ago is not active, whatever the server's zone. */
    public function testAPassIsDeadOnceItsWallClockWindowHasPassed(): void
    {
        self::bootKernel();

        $pass = new GuestPass();
        // As Doctrine hydrates it: a bare string, no offset, in the default zone.
        $pass->setActiveUntil(new \DateTime('2026-09-09 11:22:43'));

        $this->assertFalse(
            $pass->isActiveAt(new \DateTime('2026-09-09 14:02:00', new \DateTimeZone('Europe/Kyiv'))),
            'a window that closed at 11:22 must not still answer «дійсний» at 14:02',
        );
        $this->assertTrue(
            $pass->isActiveAt(new \DateTime('2026-09-09 10:00:00', new \DateTimeZone('Europe/Kyiv'))),
        );
    }
}
