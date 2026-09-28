<?php

namespace App\Tests\Entity;

use App\Entity\TelegramUser;
use PHPUnit\Framework\TestCase;

/**
 * The line under «Чужі бронювання» that tells a resident who holds an hour.
 *
 * The phone is on it deliberately — residents use it to reach whoever has the альтанка —
 * so this pins only that a missing surname or username is skipped, not printed as «null».
 */
class BookingContactLineTest extends TestCase
{
    public function testMissingPartsAreSkippedNotPrintedAsNull(): void
    {
        $user = (new TelegramUser())->setPhoneNumber('+380991112233')->setFirstName('Олена');

        $this->assertSame('+380991112233 Олена', $user->concatNameInfo());
    }

    public function testEveryPartIsKeptWhenPresent(): void
    {
        $user = (new TelegramUser())
            ->setPhoneNumber('+380991112233')
            ->setFirstName('Олена')
            ->setLastName('Коваленко')
            ->setUsername('olena_k');

        $this->assertSame('+380991112233 Олена Коваленко olena_k', $user->concatNameInfo());
    }

    public function testTheRegistryNameWinsOverTheTelegramOne(): void
    {
        $user = (new TelegramUser())
            ->setPhoneNumber('+380991112233')
            ->setFirstName('Олена')
            ->setLastName('К.')
            ->setFullName('Коваленко Олена Іванівна');

        $this->assertSame('+380991112233 Коваленко Олена Іванівна', $user->concatNameInfo());
    }

    public function testBlankPartsDoNotLeaveDoubleSpaces(): void
    {
        $user = (new TelegramUser())->setPhoneNumber('+380991112233')->setFirstName(' ')->setLastName('Коваленко');

        $this->assertSame('+380991112233 Коваленко', $user->concatNameInfo());
    }
}
