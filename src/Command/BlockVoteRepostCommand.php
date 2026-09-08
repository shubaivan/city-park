<?php

namespace App\Command;

use App\Repository\BlockVoteCampaignRepository;
use App\Service\BlockVoteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Redraw a vote's post in the residents' chat.
 *
 * A post can be wrong while the campaign is right — one went out with no question in it on
 * 08.09.2026, because creating a campaign used to publish before the question was written.
 * Editing the existing message beats deleting and re-posting: the same message, no second
 * notification for the house, and whatever discussion is under it stays there.
 */
#[AsCommand(
    name: 'block-vote:repost',
    description: 'Redraw a vote\'s post in the chat from the campaign as it stands.',
)]
class BlockVoteRepostCommand extends Command
{
    public function __construct(
        private BlockVoteCampaignRepository $campaigns,
        private BlockVoteService $voteService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Campaign id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $campaign = $this->campaigns->find((int)$input->getArgument('id'));

        if ($campaign === null) {
            $io->error('Такого голосування немає.');

            return Command::FAILURE;
        }

        $io->section('Що буде в пості');
        $io->writeln('  ' . str_replace("\n", "\n  ", strip_tags($this->voteService->chatPost($campaign))));

        $this->voteService->repost($campaign);

        $io->success($campaign->getChatMessageId() === null
            ? 'Опубліковано новий пост.'
            : sprintf('Пост %d перемальовано.', $campaign->getChatMessageId()));

        return Command::SUCCESS;
    }
}
