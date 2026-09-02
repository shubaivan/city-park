<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\DebtBoardService;
use PHPUnit\Framework\TestCase;

/**
 * The debtors' board publishes apartment numbers to the whole house, so the rules that
 * keep it honest are the ones worth pinning down. Each test here corresponds to a way
 * the board could turn from a nudge into an accusation:
 *
 *  - showing it to somebody who is not a resident;
 *  - publishing figures old enough that the "debtor" has since paid;
 *  - leaking anything beyond an apartment number.
 */
class DebtBoardRulesTest extends TestCase
{
    private function account(int $id, string $apartment, string $debt, string $house = '23'): Account
    {
        $account = (new Account())
            ->setApartmentNumber($apartment)
            ->setAccountNumber('3200' . $apartment)
            ->setHouseNumber($house)
            ->setStreet('Козацька')
            ->setDebt($debt);

        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setValue($account, $id);

        return $account;
    }

    /**
     * @param Account[] $debtors
     */
    private function service(array $debtors, ?\DateTimeImmutable $importedAt): DebtBoardService
    {
        $repo = $this->createMock(AccountRepository::class);

        $repo->method('lastDebtImportAt')->willReturn($importedAt);
        $repo->method('findDebtors')->willReturnCallback(
            static fn(int $limit = 0) => $limit > 0 ? array_slice($debtors, 0, $limit) : $debtors,
        );
        $repo->method('debtTotals')->willReturn([
            'total' => array_sum(array_map(static fn(Account $a) => (float)$a->getDebt(), $debtors)),
            'debtors' => count($debtors),
        ]);

        return new DebtBoardService($repo);
    }

    private function freshBoard(): DebtBoardService
    {
        return $this->service(
            [
                $this->account(1, '134', '12269.00'),
                $this->account(2, '89', '6268.00'),
                $this->account(3, '76', '5402.00'),
                $this->account(4, '85', '2732.00'),
                $this->account(5, '59', '2500.00'),
                $this->account(6, '39', '2168.00'),
            ],
            new \DateTimeImmutable('-2 days'),
        );
    }

    public function testUnlinkedVisitorSeesNothingOnTheMenu(): void
    {
        $this->assertSame('', $this->freshBoard()->menuBlock(null));
    }

    public function testUnlinkedVisitorIsRefusedTheReportWithoutSeeingAnyApartment(): void
    {
        $report = $this->freshBoard()->report(null);

        $this->assertStringNotContainsString('134', $report);
        $this->assertStringContainsString('/phone', $report);
    }

    public function testStaleFiguresHideTheBoardEntirely(): void
    {
        $stale = $this->service(
            [$this->account(1, '134', '12269.00')],
            new \DateTimeImmutable('-' . (DebtBoardService::STALE_AFTER_DAYS + 1) . ' days'),
        );

        $this->assertFalse($stale->isAvailable());
        $this->assertSame('', $stale->menuBlock($this->account(9, '5', '0')));
        $this->assertStringNotContainsString('кв. 134', $stale->report($this->account(9, '5', '0')));
    }

    public function testNeverImportedCountsAsStale(): void
    {
        $never = $this->service([$this->account(1, '134', '12269.00')], null);

        $this->assertTrue($never->isStale());
        $this->assertFalse($never->isAvailable());
    }

    public function testMenuBlockLeadsWithTheHouseTotalAndTheTopThree(): void
    {
        $block = $this->freshBoard()->menuBlock($this->account(9, '5', '0'));

        // 12269 + 6268 + 5402 + 2732 + 2500 + 2168
        $this->assertStringContainsString('31 339 грн', $block);
        $this->assertStringContainsString('🥇 буд. 23, кв. 134', $block);
        $this->assertStringContainsString('🥈 буд. 23, кв. 89', $block);
        $this->assertStringContainsString('🥉 буд. 23, кв. 76', $block);
        $this->assertStringContainsString('4️⃣ буд. 23, кв. 85', $block);
        $this->assertStringContainsString('5️⃣ буд. 23, кв. 59', $block);
        // Sixth place is in the total and the full report, but not on the podium.
        $this->assertStringNotContainsString('кв. 39', $block);
    }

    public function testBoardIsAlwaysDated(): void
    {
        $asOf = (new \DateTimeImmutable('-2 days'))
            ->setTimezone(new \DateTimeZone('Europe/Kyiv'))
            ->format('d.m.Y');

        $this->assertStringContainsString($asOf, $this->freshBoard()->menuBlock($this->account(9, '5', '0')));
        $this->assertStringContainsString($asOf, $this->freshBoard()->report($this->account(9, '5', '0')));
    }

    public function testDebtorSeesTheirOwnStandingAndIsMarkedInTheReport(): void
    {
        $viewer = $this->account(2, '89', '6268.00');

        $this->assertStringContainsString('2 місце', $this->freshBoard()->menuBlock($viewer));
        $this->assertStringContainsString('(це ви)', $this->freshBoard()->report($viewer));
    }

    public function testResidentWithoutDebtIsToldSo(): void
    {
        $this->assertStringContainsString(
            'боргів не має',
            $this->freshBoard()->menuBlock($this->account(9, '5', '0')),
        );
    }

    public function testReportListsEveryDebtorLargestFirst(): void
    {
        $report = $this->freshBoard()->report($this->account(9, '5', '0'));

        $this->assertSame(
            ['134', '89', '76', '85', '59', '39'],
            $this->orderOf($report, ['134', '89', '76', '85', '59', '39']),
        );
        $this->assertStringContainsString('6 квартир', $report);
    }

    /**
     * The ЖК is five buildings on one street and apartment numbers repeat across them.
     * Naming a debtor by apartment alone points at every family with that number in the
     * whole complex — on prod, "кв. 76" was one household owing 5 402 грн and another
     * owing 651. The building has to appear on every line.
     */
    public function testRepeatedApartmentNumbersAreSeparatedByBuilding(): void
    {
        $board = $this->service(
            [
                $this->account(1, '76', '5401.85', house: '23'),
                $this->account(2, '76', '651.00', house: '17'),
            ],
            new \DateTimeImmutable('-1 day'),
        );

        $report = $board->report($this->account(9, '5', '0'));

        $this->assertStringContainsString('буд. 23, кв. 76 — <b>5 402 грн</b>', $report);
        $this->assertStringContainsString('буд. 17, кв. 76 — <b>651 грн</b>', $report);
    }

    /**
     * @param string[] $apartments
     * @return string[]
     */
    private function orderOf(string $report, array $apartments): array
    {
        // Pairs, not a keyed map: PHP turns the numeric-looking apartment keys into
        // ints and the comparison against the expected strings then fails on type.
        $positions = array_map(
            static fn(string $apartment) => [$apartment, mb_strpos($report, 'кв. ' . $apartment)],
            $apartments,
        );
        usort($positions, static fn(array $a, array $b) => $a[1] <=> $b[1]);

        return array_map(static fn(array $pair) => $pair[0], $positions);
    }
}
