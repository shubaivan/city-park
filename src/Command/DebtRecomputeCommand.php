<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\AccountStatusLog;
use App\Repository\AccountRepository;
use App\Service\AccountStatusAuditor;
use App\Service\DebtPolicy;
use App\Service\PavilionPhotoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'debt:recompute',
    description: 'Re-evaluate every account against the per-account debt threshold and flip is_active accordingly. Use after changing tariff or area without waiting for a new debt-file upload.',
)]
class DebtRecomputeCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private AccountRepository $accountRepository,
        private DebtPolicy $debtPolicy,
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private AccountStatusAuditor $auditor,
        private PavilionPhotoService $photoService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print planned changes without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');

        $toBlock = [];
        $toUnblock = [];

        foreach ($this->accountRepository->findAll() as $account) {
            /** @var Account $account */
            $debt = (float)($account->getDebt() ?? 0);
            $threshold = $this->debtPolicy->getThresholdFor($account);
            $isActive = $account->isActive() === true;

            if ($debt > $threshold && $isActive) {
                $toBlock[] = ['account' => $account, 'debt' => $debt, 'threshold' => $threshold];
                continue;
            }

            // Only unblock accounts that are inactive AND have debt at/under threshold.
            // We do NOT touch accounts inactive for other reasons (e.g. admin-paused
            // with debt=0 awaiting verification — caught by hadDebt-style guard).
            if (!$isActive && $debt <= $threshold && $debt > 0) {
                $toUnblock[] = ['account' => $account, 'debt' => $debt, 'threshold' => $threshold];
            }
        }

        $io->writeln(sprintf('Plan: block %d, unblock %d', count($toBlock), count($toUnblock)));
        foreach ($toBlock as $r) {
            $io->writeln(sprintf(
                '  [BLOCK]   acc=%s кв.%s debt=%.2f > поріг=%.2f',
                $r['account']->getAccountNumber(), $r['account']->getApartmentNumber(),
                $r['debt'], $r['threshold']
            ));
        }
        foreach ($toUnblock as $r) {
            $io->writeln(sprintf(
                '  [UNBLOCK] acc=%s кв.%s debt=%.2f <= поріг=%.2f',
                $r['account']->getAccountNumber(), $r['account']->getApartmentNumber(),
                $r['debt'], $r['threshold']
            ));
        }

        if ($dryRun) {
            $io->success('[DRY-RUN] No changes written.');
            return Command::SUCCESS;
        }

        $blockedCount = 0; $unblockedCount = 0; $notified = 0;

        foreach ($toBlock as $r) {
            $account = $r['account'];
            $account->setIsActive(false);
            $this->auditor->log(
                $account, true, false,
                AccountStatusLog::SOURCE_DEBT_RECOMPUTE,
                'debt',
                sprintf('debt=%.2f, threshold=%.2f', $r['debt'], $r['threshold']),
            );
            $this->em->persist($account);
            $blockedCount++;

            foreach ($account->getUsers() as $user) {
                if (!$user->getChatId()) continue;
                try {
                    $this->bot->sendMessage(
                        text: sprintf(
                            "🚫 Вас <b>ЗАБЛОКОВАНО</b> через борг: <b>%s грн</b>\n\n"
                            . "Персональний поріг для вашої квартири: <b>%s грн</b>\n"
                            . "<i>(площа × тариф ОСББ × 1.5 = 150%% місячної плати)</i>\n\n"
                            . "Сплатіть заборгованість, щоб поновити доступ до бронювання.",
                            number_format($r['debt'], 2, '.', ' '),
                            number_format($r['threshold'], 2, '.', ' ')
                        ),
                        chat_id: $user->getChatId(),
                        parse_mode: ParseMode::HTML,
                    );
                    $notified++;
                } catch (\Throwable $e) {
                    $this->logger->warning('debt:recompute block-notify failed', [
                        'user_id' => $user->getId(), 'error' => $e->getMessage()
                    ]);
                }
            }
        }

        foreach ($toUnblock as $r) {
            $account = $r['account'];

            // Debt now within threshold, but a standing photo block keeps the account down
            // until an admin clears it (is_active is shared between debt and photo blocks).
            if ($this->photoService->hasOpenBlockingRequest($account)) {
                $this->logger->info('debt:recompute: debt OK but kept blocked by open photo request', [
                    'account_id' => $account->getId(),
                ]);
                continue;
            }

            // A community vote-block is time-boxed (block-vote:tally lifts it). Don't let a
            // debt change short-circuit the 30-day window the neighbours voted for.
            if ($account->isUnderVoteBlock()) {
                $this->logger->info('debt:recompute: debt OK but kept blocked by active vote-block', [
                    'account_id' => $account->getId(),
                ]);
                continue;
            }

            $account->setIsActive(true);
            $this->auditor->log(
                $account, false, true,
                AccountStatusLog::SOURCE_DEBT_RECOMPUTE,
                'debt',
                sprintf('debt=%.2f, threshold=%.2f', $r['debt'], $r['threshold']),
            );
            $this->em->persist($account);
            $unblockedCount++;

            foreach ($account->getUsers() as $user) {
                if (!$user->getChatId()) continue;
                try {
                    $this->bot->sendMessage(
                        text: sprintf(
                            "✅ <b>Доступ до бронювання відновлено</b>.\n\n"
                            . "Ваш поточний борг <b>%s грн</b> тепер у межах персонального порогу <b>%s грн</b>\n"
                            . "<i>(площа × тариф ОСББ × 1.5 = 150%% місячної плати)</i>.\n\n"
                            . "Можна знову бронювати.",
                            number_format($r['debt'], 2, '.', ' '),
                            number_format($r['threshold'], 2, '.', ' ')
                        ),
                        chat_id: $user->getChatId(),
                        parse_mode: ParseMode::HTML,
                    );
                    $notified++;
                } catch (\Throwable $e) {
                    $this->logger->warning('debt:recompute unblock-notify failed', [
                        'user_id' => $user->getId(), 'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->em->flush();

        $this->logger->info('debt:recompute applied', [
            'blocked' => $blockedCount,
            'unblocked' => $unblockedCount,
            'notified' => $notified,
        ]);

        $io->success(sprintf('Готово. Заблоковано: %d, розблоковано: %d, повідомлень: %d', $blockedCount, $unblockedCount, $notified));
        return Command::SUCCESS;
    }
}
