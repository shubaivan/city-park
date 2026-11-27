<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The apartment area registry: read it, and write each object's площа from it.
 *
 * Lifted out of the /admin/area upload on 27.11.2026, the day that form was closed. At 11:34
 * the registry was uploaded into the *debt* form by mistake and zeroed 438 debts; the owner's
 * call was that the house does not change, the registry was loaded once, and from now on
 * only he updates it — through `area:import-registry`, run from the console. The parsing is
 * kept here rather than copied into the command so the console reads a file exactly the way
 * the web form did.
 *
 * Two layouts are in circulation: the short one (`ID | Особовий рахунок | Загальна площа`)
 * the web form documented, and the ОСББ's full register (`ID | Тип прим. | № прим. |
 * Особовий рахунок | Загальна площа`). The columns are found by their header, and only a
 * sheet with no recognisable header falls back to B/C — the web form's fixed columns,
 * which on the full register would have read «№ прим.» as the area.
 */
class AreaRegistryImport
{
    public function __construct(
        private AccountRepository $accounts,
        private EntityManagerInterface $em,
    ) {}

    /** @return array{rows: array<string, float>, skipped: int, sheet: string} */
    public function read(string $path): array
    {
        return $this->parse(IOFactory::load($path));
    }

    /**
     * @return array{rows: array<string, float>, skipped: int, sheet: string}
     *         rows: особовий рахунок => площа, м²; skipped: rows with a zero or non-numeric area
     */
    public function parse(Spreadsheet $book): array
    {
        [$sheet, $accountCol, $areaCol] = $this->locate($book);

        $rows = [];
        $skipped = 0;

        for ($i = 2, $last = $sheet->getHighestRow(); $i <= $last; $i++) {
            $number = trim((string)$sheet->getCell($accountCol . $i)->getValue());
            $area = trim((string)$sheet->getCell($areaCol . $i)->getValue());

            if ($number === '' || $area === '') {
                continue;
            }

            $value = (float)str_replace(',', '.', $area);

            if ($value <= 0) {
                $skipped++;
                continue;
            }

            $rows[$number] = $value;
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'sheet' => $sheet->getTitle()];
    }

    /**
     * Write the areas onto the objects that exist. Never creates, never deletes, and
     * touches nothing but `area` — debts, blocks and owners are not this file's business.
     *
     * @param array<string, float> $rows
     * @return array{updated: int, unchanged: int, not_found: list<string>, changes: list<array{string, ?string, string}>}
     */
    public function apply(array $rows, bool $dryRun = false): array
    {
        $updated = $unchanged = 0;
        $notFound = $changes = [];

        foreach ($rows as $number => $area) {
            $number = (string)$number;
            $account = $this->accounts->findOneBy(['account_number' => $number]);

            if (!$account instanceof Account) {
                $notFound[] = $number;
                continue;
            }

            $new = number_format($area, 2, '.', '');
            $old = $account->getArea();

            if ($old !== null && number_format((float)$old, 2, '.', '') === $new) {
                $unchanged++;
                continue;
            }

            $changes[] = [$number, $old, $new];
            $updated++;

            if (!$dryRun) {
                $account->setArea($new);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return ['updated' => $updated, 'unchanged' => $unchanged, 'not_found' => $notFound, 'changes' => $changes];
    }

    /** @return array{Worksheet, string, string} */
    private function locate(Spreadsheet $book): array
    {
        foreach ($book->getAllSheets() as $sheet) {
            $account = $area = null;

            foreach (range('A', 'H') as $col) {
                $title = mb_strtolower((string)$sheet->getCell($col . '1')->getValue());

                if ($account === null && str_contains($title, 'особов')) {
                    $account = $col;
                } elseif ($area === null && str_contains($title, 'площ')) {
                    $area = $col;
                }
            }

            if ($account !== null && $area !== null) {
                return [$sheet, $account, $area];
            }
        }

        return [$book->getActiveSheet(), 'B', 'C'];
    }
}
