<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\ExpectedResident;
use App\Entity\TelegramUser;
use App\Service\PhoneKey;
use PHPUnit\Framework\TestCase;

/**
 * The phone numbers the ОСББ expects on an object, and the rules that make them safe.
 *
 * This is the one mechanism in the bot that attaches a person to a flat **without a human
 * looking at it**, so every rule here is load-bearing: a number that matches too loosely
 * hands somebody another household's flat, its debts and its bookings, and the only person
 * who would notice is the owner of the other flat, who is not looking.
 */
class ExpectedResidentRulesTest extends TestCase
{
    private function account(string $number = '220050'): Account
    {
        return (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber('50')
            ->setHouseNumber('19')
            ->setStreet('Козацька');
    }

    /**
     * Every shape the same number is written in has to produce the same key, or the
     * recognition silently fails and the resident is told they are in no registry.
     * Telegram reports `380932729951`, the accountant types `+380 93 272 99 51`, and a
     * number off a piece of paper is as often `0932729951`.
     */
    public function testEveryWrittenShapeOfOneNumberMatches(): void
    {
        $expected = new ExpectedResident($this->account(), '+380 93 272 99 51');

        foreach (['380932729951', '+380932729951', '0932729951', '93 272 99 51', '(093) 272-99-51'] as $written) {
            $this->assertSame(
                $expected->getPhoneKey(),
                PhoneKey::of($written),
                sprintf('«%s» must be the same phone as the one written down', $written),
            );
        }
    }

    /**
     * A key short enough to be a typo must match nothing at all.
     *
     * An empty key compared against an empty key is equal, and if that ever counted as a
     * match, the first blank row in the table would hand its flat to whoever typed a blank
     * into the panel.
     */
    public function testTooShortToBeAPhoneMatchesNothing(): void
    {
        $this->assertSame('', PhoneKey::of('12345'));
        $this->assertSame('', PhoneKey::of(''));
        $this->assertSame('', PhoneKey::of(null));

        $this->assertFalse(PhoneKey::same('', ''));
        $this->assertFalse(PhoneKey::same(null, null));
        $this->assertFalse(PhoneKey::same('12345', '12345'));
    }

    /** Different numbers stay different, including ones that share a tail but not nine digits. */
    public function testDifferentNumbersDoNotMatch(): void
    {
        $this->assertFalse(PhoneKey::same('+380932729951', '+380932729952'));
        $this->assertFalse(PhoneKey::same('380932729951', '380672729951'));
    }

    /**
     * Claiming is stamped once and never re-stamped.
     *
     * The record is what tells the person who wrote the number down that it worked, and a
     * second /phone from the same resident must not rewrite the date on which they first
     * arrived — nor move the record onto somebody else.
     */
    public function testClaimIsStampedOnceAndKeepsTheFirstArrival(): void
    {
        $expected = new ExpectedResident($this->account(), '+380932729951', 'Іван Доненко', 'alina');
        $this->assertFalse($expected->isClaimed());

        $first = (new TelegramUser())->setTelegramId('111');
        $expected->claim($first);

        $this->assertTrue($expected->isClaimed());
        $at = $expected->getClaimedAt();
        $this->assertSame($first, $expected->getClaimedBy());

        $expected->claim((new TelegramUser())->setTelegramId('222'));

        $this->assertSame($first, $expected->getClaimedBy(), 'a second claim must not move the record');
        $this->assertSame($at, $expected->getClaimedAt());
    }

    /** What the ОСББ typed is kept as typed — the accountant has to recognise her own entry. */
    public function testThePhoneIsStoredAsItWasGiven(): void
    {
        $expected = new ExpectedResident($this->account(), '  +380 93 272 99 51 ', '  Іван Доненко  ', 'alina');

        $this->assertSame('+380 93 272 99 51', $expected->getPhone());
        $this->assertSame('Іван Доненко', $expected->getFullName());
        $this->assertSame('932729951', $expected->getPhoneKey());
        $this->assertSame('alina', $expected->getCreatedBy());
    }

    /** No name given is no name, never an empty string pretending to be one. */
    public function testAnEmptyNameIsNull(): void
    {
        $this->assertNull((new ExpectedResident($this->account(), '+380932729951', '   '))->getFullName());
        $this->assertNull((new ExpectedResident($this->account(), '+380932729952'))->getFullName());
    }

    /**
     * The registry of residents and the register of expected numbers must agree on what
     * counts as the same phone.
     *
     * `TelegramUserRepository::findAccountByConditionalPhone()` recognises a family
     * member's number, this recognises an owner's before they are in the bot, and the day
     * the two disagree is the day one of them links somebody the other would not.
     */
    public function testBothRecognisersShareOneDefinition(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Repository/TelegramUserRepository.php');

        $this->assertStringContainsString(
            'return PhoneKey::of($phone);',
            $source,
            'the conditional-phone lookup must normalise through PhoneKey, not its own copy of the rule',
        );
    }
}
