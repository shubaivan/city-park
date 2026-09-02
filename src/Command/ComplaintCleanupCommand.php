<?php

namespace App\Command;

use App\Entity\Complaint;
use App\Repository\ComplaintRepository;
use App\Service\ComplaintService;
use App\Service\ImageStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keep the register a list of live problems rather than an archive.
 *
 * Two rules, both measured from `status_changed_at` — the last time anything happened to
 * the entry, not the day it was filed:
 *
 * - finished complaints go a month after they were closed (long enough to answer "коли
 *   це полагодили?");
 * - untouched ones go after half a year, on the reasoning that a problem nobody resolved
 *   in six months was not a real one and will be filed again if it still matters.
 *
 * Photos are deleted with the row. Nothing else references them, and leaving orphaned
 * files under public/uploads is how a disk fills up quietly.
 */
#[AsCommand(
    name: 'complaint:cleanup',
    description: 'Purge finished complaints after a month and untouched ones after half a year',
)]
class ComplaintCleanupCommand extends Command
{
    public function __construct(
        private ComplaintRepository $complaints,
        private ImageStore $images,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would go, delete nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $now = new \DateTimeImmutable();

        $done = $this->complaints->findExpiredDone(
            $now->modify(sprintf('-%d days', Complaint::DONE_RETENTION_DAYS)),
        );

        $stale = $this->complaints->findStaleOpen(
            $now->modify(sprintf('-%d days', Complaint::STALE_OPEN_DAYS)),
        );

        $files = 0;

        foreach ([...$done, ...$stale] as $complaint) {
            $io->writeln(sprintf(
                '  #%d [%s] %s — %s',
                $complaint->getId(),
                $complaint->getStatus(),
                $complaint->getStatusChangedAt()->format('Y-m-d'),
                mb_substr($complaint->getText(), 0, 60),
            ));

            if ($dryRun) {
                continue;
            }

            foreach ($complaint->getPhotos() as $path) {
                $this->images->delete($path, ComplaintService::PHOTO_DIR);
                $files++;
            }

            $this->em->remove($complaint);
        }

        if ($dryRun) {
            $io->success(sprintf(
                '[DRY-RUN] Видалилось би: виконаних %d, без руху %d.',
                count($done),
                count($stale),
            ));

            return Command::SUCCESS;
        }

        $this->em->flush();

        $this->logger->info('complaint:cleanup', [
            'done_purged' => count($done),
            'stale_purged' => count($stale),
            'files_deleted' => $files,
        ]);

        $io->success(sprintf(
            'Готово. Видалено виконаних: %d, без руху: %d, файлів: %d.',
            count($done),
            count($stale),
            $files,
        ));

        return Command::SUCCESS;
    }
}
