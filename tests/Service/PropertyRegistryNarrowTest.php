<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Service\PropertyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The register's filter bar, now that it runs on the server.
 *
 * It used to filter cards in the browser, which was fine for 172 objects. The ОСББ's own
 * register turned out to hold 966, the page grew to 2.4 MB, and the same three questions
 * — text, kind, building — moved here. These tests pin the two things that would quietly
 * break somebody's day: the filters AND together (a person asks «паркінг у 19-му»), and
 * the type is findable by every word anyone uses for it — the accountant's file says
 * «паркінг», the bot says «паркомісце», and both have to work.
 */
class PropertyRegistryNarrowTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            $this->row('110045', '17', '45', Account::UNIT_APARTMENT, 'Квартира', 0.0),
            $this->row('217138', '19', '138', Account::UNIT_PARKING, 'Паркомісце', 0.0),
            $this->row('215168', '19', '168', Account::UNIT_STORAGE, 'Комірчина', 1200.0, [
                (new TelegramUser())
                    ->setFirstName('Олена')
                    ->setLastName('Коваленко')
                    ->setUsername('olena')
                    ->setPhoneNumber('380501112233'),
            ]),
        ];
    }

    /**
     * @param TelegramUser[] $owners
     * @return array<string, mixed>
     */
    private function row(string $number, string $house, string $unit, string $type, string $label, float $debt, array $owners = []): array
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setHouseNumber($house)
            ->setApartmentNumber($unit)
            ->setStreet('Козацька');

        return [
            'account' => $account,
            'type' => $type,
            'type_label' => $label,
            'place' => $account->getPlaceLabel(),
            'debt' => $debt,
            'block' => null,
            'owners' => $owners,
            'siblings' => [],
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function numbers(array $rows): array
    {
        return array_map(static fn (array $r): string => (string)$r['account']->getAccountNumber(), $rows);
    }

    public function testNoFilterKeepsEverything(): void
    {
        $this->assertCount(3, PropertyRegistry::narrow($this->rows(), '', '', ''));
    }

    public function testTheKindChipPicksOneType(): void
    {
        $this->assertSame(['215168'], $this->numbers(PropertyRegistry::narrow($this->rows(), '', 'storage', '')));
    }

    /** «паркінг у 19-му» — the reason the three filters AND rather than replace each other. */
    public function testKindAndBuildingNarrowTogether(): void
    {
        $this->assertSame(['217138'], $this->numbers(PropertyRegistry::narrow($this->rows(), '', 'parking', '19')));
        $this->assertSame([], PropertyRegistry::narrow($this->rows(), '', 'parking', '17'));
    }

    /**
     * @dataProvider words
     */
    public function testTheTypeIsFoundByAnyWordPeopleUseForIt(string $typed, string $expected): void
    {
        $this->assertSame([$expected], $this->numbers(PropertyRegistry::narrow($this->rows(), $typed, '', '')));
    }

    /** @return array<string, array{string, string}> */
    public static function words(): array
    {
        return [
            'the register says паркінг' => ['паркінг', '217138'],
            'the bot says паркомісце' => ['паркомісце', '217138'],
            'the register says комора' => ['комора', '215168'],
            'the bot says комірчина' => ['комірчина', '215168'],
        ];
    }

    public function testSearchLooksAtTheAccountNumberAndTheOwners(): void
    {
        $this->assertSame(['110045'], $this->numbers(PropertyRegistry::narrow($this->rows(), '110045', '', '')));
        $this->assertSame(['215168'], $this->numbers(PropertyRegistry::narrow($this->rows(), 'олена', '', '')));
        $this->assertSame(['215168'], $this->numbers(PropertyRegistry::narrow($this->rows(), '380501112233', '', '')));
    }

    public function testDebtAndUnownedChipsReadTheRowNotTheText(): void
    {
        $this->assertSame(['215168'], $this->numbers(PropertyRegistry::narrow($this->rows(), '', 'debt', '')));
        $this->assertSame(['110045', '217138'], $this->numbers(PropertyRegistry::narrow($this->rows(), '', 'unowned', '')));
    }
}
