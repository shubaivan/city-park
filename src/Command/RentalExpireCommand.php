<?php

namespace App\Command;

use App\Service\RentalListingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'rental:expire',
    description: 'Ask owners whether their rental listing is still current, and close the ones whose lifetime ran out. Run daily.',
)]
class RentalExpireCommand extends Command
{
    public function __construct(
        private RentalListingService $rentalService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $prompted = $this->rentalService->sendDueRenewPrompts();
        $closed = $this->rentalService->closeExpired();

        $this->logger->info('rental:expire done', [
            'renew_prompts' => $prompted,
            'closed' => $closed,
        ]);

        $io->success(sprintf(
            'Готово. Запитів «ще актуально?»: %d, знято за строком: %d',
            $prompted,
            $closed
        ));

        return Command::SUCCESS;
    }
}
