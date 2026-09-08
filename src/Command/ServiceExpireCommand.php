<?php

namespace App\Command;

use App\Service\ServiceOfferService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps the services board a list of people who actually still take work.
 *
 * A trade board rots faster than it looks: somebody advertises tiling, finishes the two
 * flats they had time for and stops answering, and their card stays at the top of the
 * list telling neighbours to ring a number that no longer wants the call. Asking every
 * 30 days is cheap for the author (one tap) and is the only thing standing between this
 * and the pinboard by the lift.
 */
#[AsCommand(
    name: 'service:expire',
    description: 'Ask residents whether their service offer is still current, and close the ones whose lifetime ran out. Run daily.',
)]
class ServiceExpireCommand extends Command
{
    public function __construct(
        private ServiceOfferService $offerService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $prompted = $this->offerService->sendDueRenewPrompts();
        $closed = $this->offerService->closeExpired();

        $this->logger->info('service:expire done', [
            'renew_prompts' => $prompted,
            'closed' => $closed,
        ]);

        $io->success(sprintf(
            'Готово. Запитів «ще актуально?»: %d, знято за строком: %d',
            $prompted,
            $closed,
        ));

        return Command::SUCCESS;
    }
}
