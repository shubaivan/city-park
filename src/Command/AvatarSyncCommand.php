<?php

namespace App\Command;

use App\Repository\TelegramUserRepository;
use App\Service\AvatarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refresh the cached Telegram avatars — weekly, not on page render.
 *
 * Three Telegram calls per person against 449 of them is minutes, and the panel must not
 * wait on any of it: a table that renders in eight seconds because it is downloading
 * pictures is worse than one with no pictures. So the page reads the cache and this fills
 * it, skipping anybody looked at inside `AvatarService::STALE_AFTER_DAYS`.
 *
 * It is also the half that **removes**: somebody who closes their profile has taken the
 * photo back, and the sync is what notices.
 */
#[AsCommand(
    name: 'telegram:avatars:sync',
    description: 'Cache residents\' Telegram profile photos for the admin panel',
)]
class AvatarSyncCommand extends Command
{
    public function __construct(
        private TelegramUserRepository $users,
        private AvatarService $avatars,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Re-ask about everybody, not only the stale ones')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N people', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say who would be asked about, ask nobody');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stale = (new \DateTime())->modify('-' . AvatarService::STALE_AFTER_DAYS . ' days');
        $limit = max(0, (int)$input->getOption('limit'));
        $all = (bool)$input->getOption('all');

        $due = [];

        foreach ($this->users->findAll() as $user) {
            $checked = $user->getPhotoCheckedAt();

            if ($all || $checked === null || $checked < $stale) {
                $due[] = $user;
            }

            if ($limit > 0 && count($due) >= $limit) {
                break;
            }
        }

        if ($due === []) {
            $io->success('Усі аватарки свіжі.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('Запитали б Telegram про %d осіб.', count($due)));

            return Command::SUCCESS;
        }

        $counts = ['saved' => 0, 'removed' => 0, 'none' => 0, 'failed' => 0];

        foreach ($due as $user) {
            $counts[$this->avatars->sync($user)]++;

            usleep(AvatarService::PAUSE_MS * 1000);
        }

        $this->em->flush();

        $io->success(sprintf(
            'Оброблено %d: збережено %d, знято %d, без фото %d, помилок %d.',
            count($due),
            $counts['saved'],
            $counts['removed'],
            $counts['none'],
            $counts['failed'],
        ));

        return Command::SUCCESS;
    }
}
