<?php

namespace App\Command;

use App\Service\AreaRegistryImport;
use App\Service\ImportArchive;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Load the apartment area registry — the only way left to do it (27.11.2026).
 *
 * The /admin/area upload is closed for every login: on 27.11.2026 the registry went into
 * the debt form by mistake and zeroed 438 debts. The house does not change, so the owner
 * updates the areas himself, from here. Same parser the web form used
 * (AreaRegistryImport), and unlike `objects:import-registry` — which only fills an empty
 * area — this one overwrites an area that differs. Nothing but `area` is written.
 *
 *   bin/console area:import-registry /path/registry.xlsx --dry-run
 *   bin/console area:import-registry /path/registry.xlsx
 */
#[AsCommand(
    name: 'area:import-registry',
    description: 'Write apartment areas from the ОСББ area registry (.xlsx); the web upload is closed',
)]
class AreaImportRegistryCommand extends Command
{
    public function __construct(
        private AreaRegistryImport $import,
        private ImportArchive $archive,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the area registry .xlsx')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing');
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

        $parsed = $this->import->read($path);

        if ($parsed['rows'] === []) {
            $io->error('У файлі немає жодного рядка «особовий рахунок + площа». Нічого не змінено.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Аркуш «%s»: %d рядків, пропущено (0/нечислових): %d.', $parsed['sheet'], count($parsed['rows']), $parsed['skipped']));

        if (!$dry) {
            $this->archive->store(new UploadedFile($path, basename($path), null, null, true), ImportArchive::KIND_AREA, 'console');
        }

        $result = $this->import->apply($parsed['rows'], $dry);

        if (!$dry) {
            $this->logger->info('area:import-registry', [
                'parsed' => count($parsed['rows']),
                'updated' => $result['updated'],
                'unchanged' => $result['unchanged'],
                'not_found' => count($result['not_found']),
            ]);
        }

        $io->section($dry ? 'Що буде зроблено (dry run)' : 'Зроблено');
        $io->listing([
            sprintf('змінено площу: %d', $result['updated']),
            sprintf('без змін: %d', $result['unchanged']),
            sprintf('немає в базі: %d', count($result['not_found'])),
        ]);

        foreach (array_slice($result['changes'], 0, 20) as [$number, $old, $new]) {
            $io->writeln(sprintf('  %s: %s → %s м²', $number, $old ?? '—', $new));
        }

        if ($result['not_found'] !== []) {
            $io->writeln('Немає в базі: ' . implode(', ', array_slice($result['not_found'], 0, 30)));
        }

        return Command::SUCCESS;
    }
}
