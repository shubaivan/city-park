<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\AreaRegistryImport;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * The area registry parser, shared by the (closed) web form and `area:import-registry`.
 */
class AreaRegistryImportTest extends TestCase
{
    private function import(array $accounts = [], ?EntityManagerInterface $em = null): AreaRegistryImport
    {
        $repository = $this->createMock(AccountRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $by) => $accounts[$by['account_number']] ?? null,
        );

        return new AreaRegistryImport($repository, $em ?? $this->createMock(EntityManagerInterface::class));
    }

    public function testTheShortLayoutTheWebFormDocumented(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            ['ID', 'Особовий рахунок', 'Загальна площа'],
            [891439, '117091', '18,8'],
            [891440, '117092', '0'],
            [891441, '', 50],
        ]);

        $parsed = $this->import()->parse($book);

        $this->assertSame(['117091' => 18.8], $parsed['rows']);
        $this->assertSame(1, $parsed['skipped']);
    }

    public function testTheFullRegisterIsReadByItsHeadersNotByBAndC(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Sheet1')->fromArray([['Довідник'], ['ТОВ «Щось»']]);
        $book->createSheet()->setTitle('Лист1')->fromArray([
            ['ID', 'Тип прим.', '№ прим.', 'Особовий рахунок', 'Загальна площа'],
            [891439, 'Квартира', '85', '220085', 71.4],
            [891440, 'Паркінг', '138', '170138', 14],
        ]);

        $parsed = $this->import()->parse($book);

        $this->assertSame('Лист1', $parsed['sheet']);
        $this->assertSame(['220085' => 71.4, '170138' => 14.0], $parsed['rows']);
    }

    public function testApplyWritesOnlyChangedAreasAndADryRunWritesNothing(): void
    {
        $same = (new Account())->setAccountNumber('220085')->setArea('71.40')->setDebt('900.00');
        $changed = (new Account())->setAccountNumber('170138')->setArea('12.00');
        $rows = ['220085' => 71.4, '170138' => 14.0, '999999' => 30.0];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');
        $dry = $this->import(['220085' => $same, '170138' => $changed], $em)->apply($rows, true);

        $this->assertSame(1, $dry['updated']);
        $this->assertSame(1, $dry['unchanged']);
        $this->assertSame(['999999'], $dry['not_found']);
        $this->assertSame('12.00', $changed->getArea());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');
        $this->import(['220085' => $same, '170138' => $changed], $em)->apply($rows);

        $this->assertSame('14.00', $changed->getArea());
        $this->assertSame('900.00', $same->getDebt());
    }
}
