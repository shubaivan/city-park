<?php

namespace App\Command;

use App\Repository\BlockVoteCampaignRepository;
use App\Service\BlockVoteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Withdraw an open vote.
 *
 * The panel has the same button. This exists for the case it was written for on 08.09.2026:
 * a question that turned out to describe the wrong thing — «внесок зараховується в
 * квартплату» when it is in fact a separate one-off payment — where the honest fix is to
 * void it and ask again rather than edit the wording under people who have already answered.
 * Votes are final, so they cannot revise their own; voiding the whole campaign is the only
 * way to give them the question they were actually meant to be asked.
 *
 * A cancelled campaign keeps its ballots and its tally, and the chat post becomes «🗳
 * Голосування скасовано» — it does not vanish, and it does not enter the archive as a
 * decision the house took.
 */
#[AsCommand(
    name: 'block-vote:cancel',
    description: 'Withdraw an open vote — the ballots stay, the result is not a decision.',
)]
class BlockVoteCancelCommand extends Command
{
    public function __construct(
        private BlockVoteCampaignRepository $campaigns,
        private BlockVoteService $voteService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Campaign id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be withdrawn and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $campaign = $this->campaigns->find((int)$input->getArgument('id'));

        if ($campaign === null) {
            $io->error('Такого голосування немає.');

            return Command::FAILURE;
        }

        if (!$campaign->isOpen()) {
            $io->error(sprintf('Голосування вже завершене (%s).', $campaign->getStatus()));

            return Command::FAILURE;
        }

        $io->writeln('  ' . $this->voteService->subjectLabel($campaign));
        $io->writeln(sprintf('  Бюлетенів: %d', $campaign->getBallots()->count()));

        if ($input->getOption('dry-run')) {
            $io->success('Це був dry-run — нічого не скасовано.');

            return Command::SUCCESS;
        }

        $this->voteService->cancelCampaign($campaign);

        $io->success(sprintf('Голосування №%d скасовано.', $campaign->getId()));

        return Command::SUCCESS;
    }
}
