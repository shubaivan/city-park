<?php

namespace App\Command;

use App\Message\VoteBroadcastMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Diagnostic: enqueue one VoteBroadcastMessage with non-existent ids so the handler skips
 * (no Telegram send). Used to verify the async transport + city-park-messenger worker are
 * wired and consuming on any environment, harmlessly.
 */
#[AsCommand(name: 'vote:dispatch-test', description: 'Diagnostic: enqueue a harmless no-op VoteBroadcastMessage to check the messenger worker.')]
class VoteDispatchTestCommand extends Command
{
    public function __construct(private MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bus->dispatch(new VoteBroadcastMessage(999999999, 999999999));
        $output->writeln('dispatched VoteBroadcastMessage(999999999, 999999999)');
        return Command::SUCCESS;
    }
}
