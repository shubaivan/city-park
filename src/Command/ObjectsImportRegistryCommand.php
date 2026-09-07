<?php

namespace App\Command;

use App\Entity\Account;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Create the house's objects from the ОСББ's own register of особові рахунки.
 *
 * Until this ran, the bot knew only the objects that had ever turned up in a debt file or
 * been typed in by hand — 175 of 966. The rest existed on paper and nowhere else, which is
 * the worst shape for the register to be in: the accountant cannot link a resident to a
 * flat that is not there, so a person who presses /start from a flat nobody owes anything
 * on has to wait for somebody to create it first.
 *
 * The file is the accountant's export: `ID | Тип прим. | № прим. | Особовий рахунок |
 * Загальна площа`. It carries no names — a person's link to an object stays the
 * accountant's decision, made in /admin/users, and this command never touches it.
 *
 * What it will not do, on purpose:
 * - **never writes a debt**: debts come from the debt file and nowhere else;
 * - **never touches is_active, owners or groups**: an object appearing in the register
 *   says nothing about whether somebody may book the альтанка;
 * - **never deletes**: an о/р in the bot that the register does not have is reported and
 *   left alone. There are three of those today, and two look like typos — but a command
 *   that silently drops accounts is not the place to find out.
 */
#[AsCommand(
    name: 'objects:import-registry',
    description: 'Create missing objects from the ОСББ register of особові рахунки (.xlsx)',
)]
class ObjectsImportRegistryCommand extends Command
{
    /** The ЖК numbers its черги 1..6 and its buildings 17..27; the first digit says which. */
    private const HOUSE_BY_QUEUE = ['1' => '17', '2' => '19', '3' => '21', '4' => '23', '5' => '27', '6' => '25'];

    private const STREET = 'Козацька';

    public function __construct(
        private AccountRepository $accounts,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the register .xlsx')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing')
            ->addOption('fix-labels', null, InputOption::VALUE_NONE, 'Also rewrite «Паркінг 138» to «138» where the register has a bare number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string)$input->getArgument('file');
        $dry = (bool)$input->getOption('dry-run');

        if (!is_readable($path)) {
            $io->error('Cannot read ' . $path);

            return Command::FAILURE;
        }

        $rows = $this->read($path, $io);

        if ($rows === null) {
            return Command::FAILURE;
        }

        $created = $areaFilled = $labelsFixed = $unchanged = 0;
        $unknownQueue = $samples = $wrongHouse = [];
        $seen = [];

        foreach ($rows as $row) {
            [$number, $type, $unit, $area] = $row;

            if (isset($seen[$number])) {
                continue;
            }

            $seen[$number] = true;
            $queue = substr($number, 0, 1);
            $house = self::HOUSE_BY_QUEUE[$queue] ?? null;

            if ($house === null) {
                $unknownQueue[] = $number;
                continue;
            }

            $account = $this->accounts->findOneBy(['account_number' => $number]);

            if (!$account instanceof Account) {
                if (count($samples) < 8) {
                    $samples[] = sprintf('%s · буд. %s, %s %s · %s м²', $number, $house, $type, $unit, $area ?: '—');
                }

                $created++;

                if (!$dry) {
                    $this->create($number, $house, $unit, $area, $type);
                }

                continue;
            }

            $touched = false;

            // Area is the one figure worth back-filling: the per-flat debt threshold is
            // площа × тариф × 1.5, and an object without it falls back to a flat number
            // meant for nobody in particular.
            if ($area > 0 && (float)($account->getArea() ?? 0) <= 0) {
                $areaFilled++;
                $touched = true;

                if (!$dry) {
                    $account->setArea((string)$area);
                }
            }

            if ($input->getOption('fix-labels') && $unit !== '' && $this->isSpelledOut($account->getApartmentNumber(), $unit)) {
                $labelsFixed++;
                $touched = true;

                if (!$dry) {
                    $account->setApartmentNumber($unit);
                }
            }

            // The building is derivable from the рахунок, so a row that disagrees is either
            // a typo or a wrong рахунок — and either way it means two objects can end up
            // wearing the same «буд. N, кв. M», which is how the debtors' board names the
            // wrong household. Reported, never corrected silently: the address is what
            // three residents read in their menu.
            if (trim((string)$account->getHouseNumber()) !== $house) {
                $wrongHouse[] = sprintf(
                    '%s: у базі буд. %s, за рахунком буд. %s',
                    $number,
                    $account->getHouseNumber(),
                    $house,
                );
            }

            $touched or $unchanged++;
        }

        if (!$dry) {
            $this->em->flush();
            $this->logger->info('objects registry import', [
                'created' => $created,
                'area_filled' => $areaFilled,
                'labels_fixed' => $labelsFixed,
            ]);
        }

        $io->section($dry ? 'Що буде зроблено' : 'Зроблено');
        $io->listing([
            sprintf('нових обʼєктів: %d', $created),
            sprintf('проставлено площу: %d', $areaFilled),
            sprintf('виправлено номер приміщення: %d', $labelsFixed),
            sprintf('без змін: %d', $unchanged),
        ]);

        if ($samples) {
            $io->section('Приклади нових');
            $io->listing($samples);
        }

        if ($unknownQueue) {
            $io->warning(sprintf(
                'Не зрозуміла черга (перша цифра) у %d рахунків, пропущено: %s',
                count($unknownQueue),
                implode(', ', array_slice($unknownQueue, 0, 10)),
            ));
        }

        if ($wrongHouse !== []) {
            $io->warning(sprintf('Будинок не збігається з особовим рахунком у %d рядках:', count($wrongHouse)));
            $io->listing($wrongHouse);
        }

        $this->reportOrphans($io, $seen);

        return Command::SUCCESS;
    }

    /** @return array<int, array{string, string, string, float}>|null */
    private function read(string $path, SymfonyStyle $io): ?array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        foreach ($book->getWorksheetIterator() as $sheet) {
            $header = [];

            foreach (range('A', 'H') as $col) {
                $header[$col] = mb_strtolower(trim(str_replace(["\r", "\n"], ' ', (string)$sheet->getCell($col . '1')->getValue())));
            }

            $map = [];

            foreach ($header as $col => $title) {
                if (str_contains($title, 'особовий')) {
                    $map['account'] = $col;
                } elseif (str_contains($title, 'тип')) {
                    $map['type'] = $col;
                } elseif (str_contains($title, 'прим.') || str_contains($title, '№')) {
                    $map['unit'] ??= $col;
                } elseif (str_contains($title, 'площа')) {
                    $map['area'] = $col;
                }
            }

            if (!isset($map['account'])) {
                continue;
            }

            $rows = [];

            for ($i = 2; $i <= $sheet->getHighestRow(); $i++) {
                $number = preg_replace('/\D+/', '', (string)$sheet->getCell($map['account'] . $i)->getValue());

                if ($number === '' || strlen($number) < 5) {
                    continue;
                }

                $rows[] = [
                    $number,
                    trim((string)$sheet->getCell(($map['type'] ?? 'B') . $i)->getValue()),
                    trim((string)$sheet->getCell(($map['unit'] ?? 'C') . $i)->getValue()),
                    (float)str_replace(',', '.', (string)$sheet->getCell(($map['area'] ?? 'E') . $i)->getValue()),
                ];
            }

            if ($rows !== []) {
                $io->writeln(sprintf('Читаю аркуш «%s»: %d рядків з особовим рахунком.', $sheet->getTitle(), count($rows)));

                return $rows;
            }
        }

        $io->error('У файлі не знайдено колонки «Особовий рахунок».');

        return null;
    }

    private function create(string $number, string $house, string $unit, float $area, string $type): void
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setHouseNumber($house)
            ->setApartmentNumber($unit !== '' ? $unit : substr($number, 3))
            ->setStreet(self::STREET)
            ->setIsActive(true);

        // The type is taken from the особовий рахунок, the same rule the whole bot uses —
        // the register's own wording ("Комора", "паркінг", "Паркінг") is only a check.
        $account->setUnitType($account->deriveUnitType());

        if ($area > 0) {
            $account->setArea((string)$area);
        }

        $this->em->persist($account);
    }

    /** «Паркінг 138» where the register says «138» — our own label already adds the word. */
    private function isSpelledOut(?string $current, string $unit): bool
    {
        $current = trim((string)$current);

        return $current !== '' && $current !== $unit && preg_match('/^\D+\s*' . preg_quote($unit, '/') . '$/u', $current) === 1;
    }

    /** @param array<string, bool> $seen */
    private function reportOrphans(SymfonyStyle $io, array $seen): void
    {
        $orphans = [];

        foreach ($this->accounts->findAll() as $account) {
            $number = (string)$account->getAccountNumber();

            if ($number !== '' && !isset($seen[$number])) {
                $orphans[] = sprintf('%s (%s)', $number, $account->getPlaceLabel());
            }
        }

        if ($orphans === []) {
            return;
        }

        $io->warning(sprintf(
            'У боті є %d рахунків, яких немає в реєстрі — не чіпаю, покажіть бухгалтеру: %s',
            count($orphans),
            implode(', ', $orphans),
        ));
    }
}
