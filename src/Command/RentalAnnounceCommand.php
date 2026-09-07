<?php

namespace App\Command;

use App\Entity\RentalListing;
use App\Service\RentalListingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Post the live listings that never made it into the chat.
 *
 * Adverts started going to the residents' chat on 07.09.2026; the ones published before
 * that are still current and were not there, which left the new topic empty next to a
 * noticeboard that was not. Run once after wiring the topic.
 *
 * Idempotent by construction: a listing that already carries a `chat_message_id` is
 * skipped, so running it twice does not double-post. Silent posts, oldest first, so the
 * thread reads in the order the flats were offered.
 */
#[AsCommand(
    name: 'rental:announce',
    description: 'Post active listings that have no chat post yet',
)]
class RentalAnnounceCommand extends Command
{
    public function __construct(private RentalListingService $rentals)
    {
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

        $pending = array_reverse(array_filter(
            $this->rentals->activeListings(),
            static fn (RentalListing $listing): bool => $listing->getChatMessageId() === null,
        ));

        if ($pending === []) {
            $io->success('Усі активні оголошення вже є в чаті.');

            return Command::SUCCESS;
        }

        foreach ($pending as $listing) {
            $io->writeln(sprintf(
                '%s #%d %s',
                $dry ? '[DRY]' : '  →',
                (int)$listing->getId(),
                $this->rentals->buttonLabel($listing),
            ));

            if (!$dry) {
                $this->rentals->announce($listing);
            }
        }

        $io->success(sprintf($dry ? 'Буде опубліковано: %d' : 'Опубліковано: %d', count($pending)));

        return Command::SUCCESS;
    }
}
