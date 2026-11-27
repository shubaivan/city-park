<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\DebtAnnouncer;
use App\Service\ImportArchive;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 27.11.2026, 11:34: the area registry was uploaded into /admin/debt. Nothing in it matched
 * a debt row, the "not in file" loop reset 438 debts, unblocked them, told each household
 * «✅ Ваш борг повністю погашено» and the chat «Борг 0 грн». Replayed here: the same kind
 * of file must be refused with nothing written, nothing sent and nothing announced.
 */
class AdminDebtUploadGuardTest extends WebTestCase
{
    private function loginAs(string $login): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $client->loginUser($provider->loadUserByIdentifier($login));

        return $client;
    }

    /** The ОСББ's full register: ID | Тип прим. | № прим. | Особовий рахунок | Загальна площа. */
    private function areaRegistryFile(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['ID', 'Тип прим.', '№ прим.', 'Особовий рахунок', 'Загальна площа'], null, 'A1');

        foreach (range(1, 40) as $i) {
            $sheet->fromArray([891400 + $i, 'Квартира', (string)$i, (string)(200000 + $i), 54.2], null, 'A' . ($i + 1));
        }

        $path = tempnam(sys_get_temp_dir(), 'registry_') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function testTheAreaRegistryIsRefusedAndNothingHappens(): void
    {
        $client = $this->loginAs('alina');
        $container = static::getContainer();

        $accounts = [];
        foreach (range(1, 40) as $i) {
            $accounts[] = (new Account())->setAccountNumber((string)(200000 + $i))->setDebt('2500.00')->setIsActive(false);
        }

        $repository = $this->createMock(AccountRepository::class);
        $repository->method('findAll')->willReturn($accounts);
        $repository->expects($this->never())->method('findOneBy');
        $container->set(AccountRepository::class, $repository);

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendMessage');
        $container->set(Nutgram::class, $bot);

        $announcer = $this->createMock(DebtAnnouncer::class);
        $announcer->expects($this->never())->method('afterImport');
        $container->set(DebtAnnouncer::class, $announcer);

        $archive = $this->createMock(ImportArchive::class);
        $archive->expects($this->never())->method('store');
        $archive->method('recent')->willReturn([]);
        $container->set(ImportArchive::class, $archive);

        $path = $this->areaRegistryFile();

        try {
            $client->request('POST', '/admin/debt/upload', files: [
                'debt_file' => new UploadedFile($path, 'Реєстр-особових-рахунків (43).xlsx', null, null, true),
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string)$client->getResponse()->getContent();
        $this->assertStringContainsString('Файл не прийнято', $html);
        $this->assertStringContainsString('не схоже на файл боржників', $html);
        $this->assertStringNotContainsString('Скинуто борг', $html);

        foreach ($accounts as $account) {
            $this->assertSame('2500.00', $account->getDebt());
            $this->assertFalse($account->isActive());
        }
    }
}
