<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Tariff;
use App\Repository\AccountRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\TariffRepository;
use App\Service\BlockReasonResolver;
use App\Service\DebtPolicy;
use App\Service\PavilionPhotoService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The block message names every object of the household that owes — not only the one this
 * person happens to be linked to.
 *
 * A debt on any object blocks booking for the whole owner group, and `TelegramUser` points
 * at exactly one Account. So somebody linked to a flat that owes nothing, whose комірчина is
 * over its threshold, used to read «заборгованість понад допустимий поріг» beside a flat
 * with no debt on it — an accusation with no arithmetic behind it, which is the one thing
 * this message must never be. `DebtPolicy::getBlockingSiblings()` existed for exactly this
 * and was never called.
 *
 * **The debts are not summed, and must not be.** Each object's threshold comes from its own
 * area: 50.6 м² gives 1 024 грн, a 4 м² комірчина gives about 81. Adding two debts to compare
 * against one threshold compares a total against half a rule.
 */
class BlockNamesEveryDebtorTest extends TestCase
{
    private function resolver(array $siblings): BlockReasonResolver
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findGroupSiblings')->willReturn($siblings);

        $tariff = $this->createMock(TariffRepository::class);
        $tariff->method('getOrCreate')->willReturn((new Tariff())->setPricePerMeter('13.50'));

        $requests = $this->createMock(PhotoUploadRequestRepository::class);
        $requests->method('findEarliestBlockedOpen')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);

        return new BlockReasonResolver(
            new DebtPolicy(1300, $accounts, $tariff, $em),
            $requests,
            $this->createMock(PavilionPhotoService::class),
            $tariff,
            $em,
        );
    }

    private function unit(string $number, string $unit, string $area, string $debt): Account
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber($unit)
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setArea($area);
        $account->setIsActive(false);
        $account->setDebt($debt);

        return $account;
    }

    /** The flat is clean; the storage room is what blocks. The message must say so. */
    public function testItNamesTheSiblingThatOwesEvenWhenTheFlatIsClean(): void
    {
        $flat = $this->unit('230085', '85', '50.60', '0.00');
        $storage = $this->unit('235168', '168', '4.00', '209.00');

        $message = (string)$this->resolver([$flat, $storage])->botMessage($flat, new \DateTime());

        $this->assertStringContainsString('комірчина 168', $message, 'the object that owes must be named');
        $this->assertStringContainsString('209.00', $message);
    }

    /** Both over threshold: both listed, each with its own arithmetic. */
    public function testItListsEveryObjectThatOwes(): void
    {
        $flat = $this->unit('230085', '85', '50.60', '3415.50');
        $storage = $this->unit('235168', '168', '4.00', '209.00');

        $message = (string)$this->resolver([$flat, $storage])->botMessage($flat, new \DateTime());

        $this->assertStringContainsString('кв. 85', $message);
        $this->assertStringContainsString('комірчина 168', $message);
        $this->assertStringContainsString('1 024.65', $message, 'the flat keeps its own threshold');
        $this->assertStringContainsString('81.00', $message, 'and the storage room keeps its own');

        // Not summed: 3 415.50 + 209.00 must not appear as one figure against one threshold.
        $this->assertStringNotContainsString('3 624.50', $message);
    }

    /** Each threshold is spelled out from that object's own area. */
    public function testEachObjectExplainsItsOwnThreshold(): void
    {
        $flat = $this->unit('230085', '85', '50.60', '3415.50');

        $message = (string)$this->resolver([$flat])->botMessage($flat, new \DateTime());

        $this->assertStringContainsString('50.6', $message);
        $this->assertStringContainsString('13.50', $message);
        $this->assertStringContainsString('1.5', $message);
    }
}
