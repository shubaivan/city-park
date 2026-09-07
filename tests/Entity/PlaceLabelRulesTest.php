<?php

namespace App\Tests\Entity;

use App\Entity\Account;
use PHPUnit\Framework\TestCase;

/**
 * One definition of «кв. 85» / «паркомісце 138» / «комірчина 168», and nothing outside the
 * entity may build its own.
 *
 * The rule is not stylistic. `apartment_number` on a non-flat row is usually a bare number,
 * so every hand-written `sprintf('кв. %s', …)` renames a parking space into somebody's
 * flat — and these labels are read in public: the debtors' board, the complaint posted to
 * the residents' chat, the booking history, the message a resident gets the moment the bot
 * matches their phone. It has been fixed one call site at a time (DebtBoardService and
 * PropertyRegistry on 03.09.2026, the menu header on 04.09.2026, four more on 07.09.2026);
 * this test is what stops the next copy from being written.
 */
class PlaceLabelRulesTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function units(): array
    {
        return [
            'flat'    => ['210045', '45', 'кв. 45'],
            'parking' => ['217138', '138', 'паркомісце 138'],
            'storage' => ['215168', '168', 'комірчина 168'],
        ];
    }

    /** @dataProvider units */
    public function testTheUnitWordComesFromTheAccountNumber(string $number, string $unit, string $expected): void
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber($unit)
            ->setStreet('Козацька')
            ->setHouseNumber('21');

        $this->assertSame($expected, $account->getUnitLabel());
        $this->assertSame('буд. 21, ' . $expected, $account->getPlaceLabel());
        $this->assertSame('Козацька 21, ' . $expected, $account->getStreetPlaceLabel());
    }

    /**
     * The building is never dropped: five buildings on one street repeat their apartment
     * numbers, so «кв. 76» named two households owing 5 402 and 651 грн.
     */
    public function testEveryFullLabelNamesTheBuilding(): void
    {
        $account = (new Account())
            ->setAccountNumber('210076')
            ->setApartmentNumber('76')
            ->setStreet('Козацька')
            ->setHouseNumber('19');

        $this->assertStringContainsString('19', $account->getPlaceLabel());
        $this->assertStringContainsString('19', $account->getStreetPlaceLabel());
    }

    /** Nothing under src/ may assemble a place label out of apartment_number itself. */
    public function testNoFileBuildsItsOwnFlatLabel(): void
    {
        $root = realpath(__DIR__ . '/../../src');
        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Entity/Account.php')) {
                continue;
            }

            $body = (string)file_get_contents($file->getPathname());

            // Comments explain the rule and must stay quotable; only real format strings count.
            $body = (string)preg_replace('#^\s*(//|\*|/\*).*$#m', '', $body);

            if (preg_match('/кв\.\s*%s/u', $body) === 1) {
                $offenders[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these build their own «кв. %s» — use getUnitLabel() / getPlaceLabel() / getStreetPlaceLabel()',
        );
    }
}
