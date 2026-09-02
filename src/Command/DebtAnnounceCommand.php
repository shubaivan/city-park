<?php

namespace App\Command;

use App\Repository\DebtSnapshotRepository;
use App\Service\DebtAnnouncer;
use App\Service\DebtBoardService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Post the debt summary to the residents' chat by hand.
 *
 * The announcement normally rides on a debt import, which makes it impossible to preview
 * or to re-send without re-importing. This exists so the post can be read before anyone
 * else sees it (--dry-run) and re-sent if a send failed (--force).
 */
#[AsCommand(
    name: 'debt:announce',
    description: 'Publish the debt summary (total, count, top-10) to the residents chat',
)]
class DebtAnnounceCommand extends Command
{
    public function __construct(
        private DebtAnnouncer $announcer,
        private DebtBoardService $board,
        private DebtSnapshotRepository $snapshots,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the post, send nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Send even if a post already went out today')
            ->addOption('snapshot', null, InputOption::VALUE_NONE, 'Record a fresh snapshot first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $snapshot = $input->getOption('snapshot')
            ? $this->announcer->recordSnapshot()
            : $this->snapshots->latest() ?? $this->announcer->recordSnapshot();

        if ($input->getOption('dry-run')) {
            $io->writeln($this->board->chatAnnouncement($snapshot, $this->snapshots->previousTo($snapshot)));
            $io->success('[DRY-RUN] Нічого не надіслано.');

            return Command::SUCCESS;
        }

        $result = $this->announcer->announce($snapshot, force: (bool)$input->getOption('force'));

        match ($result) {
            'sent' => $io->success('Опубліковано в чаті мешканців.'),
            'skipped:not-configured' => $io->warning('Чат мешканців не налаштовано — нічого не надіслано.'),
            'skipped:already-today' => $io->warning('Сьогодні анонс уже виходив. Повторити: --force'),
            default => $io->error('Не вдалося надіслати — дивіться var/log/prod_errors.log'),
        };

        return $result === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
