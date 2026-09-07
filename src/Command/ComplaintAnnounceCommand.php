<?php

namespace App\Command;

use App\Entity\Complaint;
use App\Repository\ComplaintRepository;
use App\Service\ComplaintService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Post the open entries the «🔧 Заявки» topic never saw.
 *
 * The register predates the topic: everything reported before 07.09.2026 was announced
 * into the chat's General thread, so the new branch opened empty next to a register that
 * was not. Run once after wiring the topic.
 *
 * Only open entries, oldest first, and only those with no chat post recorded — a finished
 * complaint does not need announcing, and running this twice cannot double-post.
 */
#[AsCommand(
    name: 'complaint:announce',
    description: 'Post open complaints that have no chat post yet',
)]
class ComplaintAnnounceCommand extends Command
{
    public function __construct(
        private ComplaintRepository $complaints,
        private ComplaintService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be posted and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool)$input->getOption('dry-run');

        $pending = array_filter(
            $this->complaints->findBy([], ['id' => 'ASC']),
            static fn (Complaint $c): bool => $c->isOpen() && $c->getChatMessageId() === null,
        );

        if ($pending === []) {
            $io->success('Усі відкриті заявки вже є в чаті.');

            return Command::SUCCESS;
        }

        $posted = 0;

        foreach ($pending as $complaint) {
            $io->writeln(sprintf(
                '%s №%d · %s · %s',
                $dry ? '[DRY]' : '  →',
                (int)$complaint->getId(),
                $complaint->getStatus(),
                mb_strimwidth((string)$complaint->getText(), 0, 60, '…'),
            ));

            if (!$dry) {
                $posted += $this->service->announceExisting($complaint) ? 1 : 0;
            }
        }

        $io->success($dry
            ? sprintf('Буде опубліковано: %d', count($pending))
            : sprintf('Опубліковано: %d із %d', $posted, count($pending)));

        return Command::SUCCESS;
    }
}
