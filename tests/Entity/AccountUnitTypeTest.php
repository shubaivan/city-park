<?php

namespace App\Tests\Entity;

use App\Entity\Account;
use PHPUnit\Framework\TestCase;

/**
 * What kind of property an account is.
 *
 * It decides the label on the public debtors' board, the address in a complaint posted to
 * the residents' chat, and whether the owner may book the pavilion. It used to be
 * recomputed from the особовий рахунок on every read — the third digit of Q-P-T-NNN — which
 * meant a mistyped number read as the *wrong type* rather than as an error, and nobody
 * could correct it because there was nothing to correct.
 */
class AccountUnitTypeTest extends TestCase
{
    private function account(string $number, string $unit = '85'): Account
    {
        return (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber($unit)
            ->setHouseNumber('19')
            ->setStreet('Козацька');
    }

    /** The formula, still the seed and the fallback: third digit 0 / 5 / 7. */
    public function testTheFormulaStillDecidesWhenNothingIsStored(): void
    {
        $this->assertSame(Account::UNIT_APARTMENT, $this->account('110001')->getUnitType());
        $this->assertSame(Account::UNIT_STORAGE, $this->account('235169', '169')->getUnitType());
        $this->assertSame(Account::UNIT_PARKING, $this->account('237191', '191')->getUnitType());

        // Legacy rows that spell it out win over the digit.
        $this->assertSame(Account::UNIT_STORAGE, $this->account('110012', 'Комірчина 12')->getUnitType());
    }

    /**
     * The reason the column exists. `42076` is five digits — the third character is the `0`
     * of what should have been `420076`, so the formula reads a position that means nothing
     * and answers "apartment" with full confidence.
     */
    public function testAStoredTypeOverridesTheFormula(): void
    {
        $mistyped = $this->account('42076', '76');

        $this->assertSame(Account::UNIT_APARTMENT, $mistyped->getUnitType(), 'the formula guesses');
        $this->assertNull($mistyped->getStoredUnitType());

        $mistyped->setUnitType(Account::UNIT_PARKING);

        $this->assertSame(Account::UNIT_PARKING, $mistyped->getUnitType());
        $this->assertSame(Account::UNIT_PARKING, $mistyped->getStoredUnitType());
        $this->assertTrue($mistyped->isParking());
        $this->assertFalse($mistyped->isApartment());
    }

    public function testAnUnknownTypeIsRefusedRatherThanStored(): void
    {
        $account = $this->account('110001');
        $account->setUnitType('office');

        $this->assertNull($account->getStoredUnitType());
        $this->assertSame(Account::UNIT_APARTMENT, $account->getUnitType());
    }

    public function testClearingTheTypeFallsBackToTheFormula(): void
    {
        $account = $this->account('237191', '191');
        $account->setUnitType(Account::UNIT_APARTMENT);
        $this->assertTrue($account->isApartment());

        $account->setUnitType(null);
        $this->assertSame(Account::UNIT_PARKING, $account->getUnitType());
    }

    /**
     * Storage owners do not pay the yard fee, so they cannot book the pavilion — and now
     * that the type is stored, correcting one row corrects that right too.
     */
    public function testBookingRightFollowsTheStoredType(): void
    {
        $flat = $this->account('110085');
        $parking = $this->account('237191', '191');
        $storage = $this->account('235169', '169');

        $this->assertTrue($flat->canBookPavilion());
        $this->assertTrue($parking->canBookPavilion(), 'parking owners pay the yard fee');
        $this->assertFalse($storage->canBookPavilion());

        // A flat wrongly recorded as a storage room loses the right; correcting it restores it.
        $flat->setUnitType(Account::UNIT_STORAGE);
        $this->assertFalse($flat->canBookPavilion());
        $flat->setUnitType(Account::UNIT_APARTMENT);
        $this->assertTrue($flat->canBookPavilion());
    }

    public function testEveryTypeHasALabel(): void
    {
        foreach (array_keys(Account::UNIT_TYPES) as $type) {
            $account = $this->account('110001');
            $account->setUnitType($type);

            $this->assertNotSame('', $account->getUnitTypeLabel());
        }
    }

    /**
     * The label every screen prints. The booking gate lists the objects of a group that owe
     * money, and that list is exactly where a комірчина or a parking space appears — calling
     * one of those "кв. 168" sends the reader to a door that has nothing to do with it.
     */
    public function testThePlaceLabelNamesTheBuildingAndTheKindOfUnit(): void
    {
        $flat = $this->account('230085', '85');
        $storage = $this->account('235168', '168');
        $parking = $this->account('237191', '191');
        $spelled = $this->account('117138', 'Паркінг 138');

        $this->assertSame('буд. 19, кв. 85', $flat->getPlaceLabel());
        $this->assertSame('буд. 19, комірчина 168', $storage->getPlaceLabel());
        $this->assertSame('буд. 19, паркомісце 191', $parking->getPlaceLabel());
        // A row that already spells it out keeps its own wording.
        $this->assertSame('буд. 19, Паркінг 138', $spelled->getPlaceLabel());

        // Correcting the type corrects the label everywhere at once.
        $storage->setUnitType(Account::UNIT_APARTMENT);
        $this->assertSame('буд. 19, кв. 168', $storage->getPlaceLabel());
    }
}
