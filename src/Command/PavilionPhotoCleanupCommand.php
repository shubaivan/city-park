<?php

namespace App\Command;

use App\Repository\PavilionPhotoRepository;
use App\Service\SchedulePavilionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pavilion:photo:cleanup',
    description: 'Delete pavilion photos (files + DB rows) older than the retention window.',
)]
class PavilionPhotoCleanupCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private string $projectDir,
        private PavilionPhotoRepository $photoRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Retention window in days (default 30).',
            (string)self::DEFAULT_RETENTION_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int)$input->getOption('days'));
        $cutoff = SchedulePavilionService::createNewDate()->modify('-' . $days . ' days');

        $photos = $this->photoRepository->findOlderThan($cutoff);
        if (!$photos) {
            $io->success(sprintf('Nothing to clean (cutoff %s).', $cutoff->format('Y-m-d H:i')));
            return Command::SUCCESS;
        }

        $publicDir = rtrim($this->projectDir, '/') . '/public';
        $deletedFiles = 0;
        $missingFiles = 0;

        foreach ($photos as $photo) {
            $abs = $publicDir . $photo->getFilePath();
            if (is_file($abs)) {
                if (@unlink($abs)) {
                    $deletedFiles++;
                } else {
                    $this->logger->warning('photo file unlink failed', ['path' => $abs]);
                }
            } else {
                $missingFiles++;
            }
            $this->em->remove($photo);
        }
        $this->em->flush();

        $io->success(sprintf(
            'Removed %d photos (files deleted: %d, files already missing: %d, cutoff %s).',
            count($photos),
            $deletedFiles,
            $missingFiles,
            $cutoff->format('Y-m-d H:i'),
        ));

        return Command::SUCCESS;
    }
}
