<?php

namespace App\Command;

use App\Service\BlockVoteService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'block-vote:tally',
    description: 'Close expired community vote-to-block campaigns (pass+block / fail) and auto-unblock accounts whose 30-day vote-block has elapsed. Run hourly.',
)]
class BlockVoteTallyCommand extends Command
{
    public function __construct(
        private BlockVoteService $voteService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $reminded = $this->voteService->sendDueFinalReminders();
        $blocked = $this->voteService->closeExpiredCampaigns();
        $unblocked = $this->voteService->autoUnblockExpired();

        $this->logger->info('block-vote:tally done', [
            'final_reminders' => $reminded,
            'blocked' => $blocked,
            'unblocked' => $unblocked,
        ]);

        $io->success(sprintf(
            'Готово. Нагадувань (останній день): %d, заблоковано за голосуванням: %d, авто-розблоковано: %d',
            $reminded, $blocked, $unblocked
        ));
        return Command::SUCCESS;
    }
}
