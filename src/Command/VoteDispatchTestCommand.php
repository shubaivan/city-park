<?php

namespace App\Command;

use App\Message\VoteBroadcastMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Diagnostic: enqueue a VoteBroadcastMessage to check the async transport + city-park-messenger
 * worker. With no options it uses non-existent ids so the handler skips (no Telegram send).
 * With --campaign and --account it enqueues a REAL notice to one account (for a targeted demo
 * to a single resident without broadcasting to everyone); add --reminder for the reminder text.
 */
#[AsCommand(name: 'vote:dispatch-test', description: 'Diagnostic: enqueue a VoteBroadcastMessage (no-op by default, or targeted to one account).')]
class VoteDispatchTestCommand extends Command
{
    public function __construct(private MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('campaign', null, InputOption::VALUE_REQUIRED, 'Campaign id (real send).');
        $this->addOption('account', null, InputOption::VALUE_REQUIRED, 'Recipient account id (real send).');
        $this->addOption('reminder', null, InputOption::VALUE_NONE, 'Use the reminder text.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $campaignId = (int)($input->getOption('campaign') ?? 0);
        $accountId = (int)($input->getOption('account') ?? 0);
        $reminder = (bool)$input->getOption('reminder');

        if ($campaignId > 0 && $accountId > 0) {
            $this->bus->dispatch(new VoteBroadcastMessage($campaignId, $accountId, $reminder));
            $output->writeln(sprintf('dispatched REAL %s for campaign=%d account=%d', $reminder ? 'reminder' : 'notice', $campaignId, $accountId));
            return Command::SUCCESS;
        }

        $this->bus->dispatch(new VoteBroadcastMessage(999999999, 999999999));
        $output->writeln('dispatched no-op VoteBroadcastMessage(999999999, 999999999)');
        return Command::SUCCESS;
    }
}
