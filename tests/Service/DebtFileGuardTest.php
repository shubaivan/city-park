<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Service\DebtFileGuard;
use PHPUnit\Framework\TestCase;

/**
 * Before the debt form writes anything it asks whether the file is a debtors file at all.
 * 27.11.2026: the area registry went through it and 438 accounts were "paid off".
 */
class DebtFileGuardTest extends TestCase
{
    /** @return list<Account> */
    private function house(int $debtors, int $clean = 0): array
    {
        $accounts = [];

        for ($i = 1; $i <= $debtors; $i++) {
            $accounts[] = (new Account())->setAccountNumber((string)(200000 + $i))->setDebt('2500.00');
        }

        for ($i = 1; $i <= $clean; $i++) {
            $accounts[] = (new Account())->setAccountNumber((string)(300000 + $i))->setDebt('0');
        }

        return $accounts;
    }

    public function testTheAreaRegistryIsRefused(): void
    {
        // The registry read as a debt file: account numbers from another column, nothing matches.
        $rows = ['891439' => 18.8, '891440' => 54.2, '891441' => 71.0];

        $check = DebtFileGuard::check($rows, $this->house(438, 500));

        $this->assertSame(0, $check['matched']);
        $this->assertNotNull($check['refusal']);
        $this->assertStringContainsString('не схоже на файл боржників', $check['refusal']);
        $this->assertStringContainsString('нічого не змінено', $check['refusal']);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->assertNotNull(DebtFileGuard::check([], $this->house(10))['refusal']);
    }

    public function testAFileThatWouldResetMoreThanHalfTheDebtorsIsRefused(): void
    {
        $house = $this->house(10, 5);
        // Names four of today's ten debtors: six would be reset.
        $rows = ['200001' => 100.0, '200002' => 100.0, '200003' => 100.0, '200004' => 100.0];

        $check = DebtFileGuard::check($rows, $house);

        $this->assertSame(4, $check['matched']);
        $this->assertSame(10, $check['debtors']);
        $this->assertSame(6, $check['would_reset']);
        $this->assertStringContainsString('6 з 10', (string)$check['refusal']);
    }

    public function testExactlyHalfIsStillAccepted(): void
    {
        $rows = [];

        foreach (range(1, 5) as $i) {
            $rows[(string)(200000 + $i)] = 100.0;
        }

        $check = DebtFileGuard::check($rows, $this->house(10));

        $this->assertSame(5, $check['would_reset']);
        $this->assertNull($check['refusal']);
    }

    public function testAnOrdinaryMonthlyFileIsAccepted(): void
    {
        // 387 of 438 debtors still owe, 20 new ones, a few rows unknown to the base.
        $house = $this->house(438, 500);
        $rows = [];

        foreach (range(1, 387) as $i) {
            $rows[(string)(200000 + $i)] = 1500.0;
        }

        foreach (range(1, 20) as $i) {
            $rows[(string)(300000 + $i)] = 900.0;
        }

        $rows['999999'] = 50.0;

        $check = DebtFileGuard::check($rows, $house);

        $this->assertSame(407, $check['matched']);
        $this->assertSame(51, $check['would_reset']);
        $this->assertNull($check['refusal']);
    }

    public function testNumericKeysFromTheSpreadsheetStillMatch(): void
    {
        // PHP turns '200001' into int 200001 as an array key.
        $check = DebtFileGuard::check([200001 => 100.0], $this->house(1));

        $this->assertSame(1, $check['matched']);
        $this->assertNull($check['refusal']);
    }

    public function testTheFirstFileIntoAnEmptyLedgerIsAccepted(): void
    {
        $check = DebtFileGuard::check(['300001' => 100.0], $this->house(0, 3));

        $this->assertSame(0, $check['debtors']);
        $this->assertNull($check['refusal']);
    }
}
