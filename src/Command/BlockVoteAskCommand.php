<?php

namespace App\Command;

use App\Service\BlockVoteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Put a question to the house from the terminal.
 *
 * The panel has the same form, and for the accountant that is the right place. This exists
 * for the questions somebody wants to compose carefully — long wording, an exact sum, text
 * pasted from elsewhere — where a one-line input in a browser is the wrong tool, and for the
 * one thing the form cannot do: run it past `--dry-run` first, when the send reaches every
 * household that can vote and cannot be recalled.
 */
#[AsCommand(
    name: 'block-vote:ask',
    description: 'Put a yes/no question to the house (advisory — nothing is enacted).',
)]
class BlockVoteAskCommand extends Command
{
    public function __construct(private BlockVoteService $voteService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'The question, answerable yes/no')
            ->addOption('details', null, InputOption::VALUE_REQUIRED, 'Background: why, how much, what next')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How long it runs', (string)BlockVoteService::VOTE_DAYS)
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Who is opening it', 'main_admin')
            ->addOption('quiet', null, InputOption::VALUE_NONE, 'Do not broadcast — leave it in the list only')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be sent and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $question = trim((string)$input->getArgument('question'));
        $details = trim((string)$input->getOption('details'));
        $days = (int)$input->getOption('days');
        $broadcast = !$input->getOption('quiet');

        if (mb_strlen($question) < 10) {
            $io->error('Сформулюйте питання — принаймні 10 символів.');

            return Command::FAILURE;
        }

        $voters = count($this->voteService->eligibleVoters());

        $io->section('Питання');
        $io->writeln('  ' . $question);

        if ($details !== '') {
            $io->section('Деталі');
            $io->writeln('  ' . wordwrap($details, 90, "\n  "));
        }

        $io->section('Кому і як довго');
        $io->writeln(sprintf('  Право голосу мають: %d', $voters));
        $io->writeln(sprintf('  Триває днів: %d', $days));
        $io->writeln('  Розсилка: ' . ($broadcast
            ? 'так — особисте повідомлення кожному виборцю і пост у топіку «🗳 Голосування»'
            : 'ні — тільки в списку голосувань'));

        if ($input->getOption('dry-run')) {
            $io->success('Це був dry-run — нічого не надіслано.');

            return Command::SUCCESS;
        }

        $campaign = $this->voteService->openQuestion(
            $question,
            $details !== '' ? $details : null,
            (string)$input->getOption('by'),
            null,
            $broadcast,
            $days,
        );

        $io->success(sprintf(
            'Питання №%d поставлено. Голосування до %s.',
            $campaign->getId(),
            $campaign->getDeadlineAt()->format('d.m.Y'),
        ));

        return Command::SUCCESS;
    }
}
