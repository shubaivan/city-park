<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\AccountStatusLog;
use App\Repository\AccountRepository;
use App\Service\AccountStatusAuditor;
use App\Service\DebtPolicy;
use App\Service\PavilionPhotoService;
use App\Service\SchedulePavilionService;
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

/**
 * One-off / on-demand: forgive accounts that were photo-blocked under the OLD
 * aggressive timing (session-end + 20 min) and notify them that the rule is now
 * gentler (block only at 09:00 next morning). Unlike `pavilion:photo:bulk-unblock`,
 * the message here does NOT claim a "photos didn't save" bug — the photos simply
 * weren't sent in time under a rule that was too strict, which we've since relaxed.
 *
 * Scope: only accounts with a CURRENTLY open blocked request (resolved_at IS NULL
 * AND blocked_at IS NOT NULL). Skips accounts whose debt is still over threshold
 * (a debt block must survive a photo forgiveness) and accounts already active.
 */
#[AsCommand(
    name: 'pavilion:photo:soft-unblock',
    description: 'Forgive accounts with an open photo-block, restore is_active (debt-permitting), and notify them with a neutral apology + reminder to send the photo promptly.',
)]
class PhotoSoftUnblockCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private AccountRepository $accountRepository,
        private PavilionPhotoService $photoService,
        private DebtPolicy $debtPolicy,
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private AccountStatusAuditor $auditor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would happen without changing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $now = SchedulePavilionService::createNewDate();

        $rows = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(r.account) AS account_id
             FROM App\Entity\PhotoUploadRequest r
             WHERE r.blocked_at IS NOT NULL AND r.resolved_at IS NULL'
        )->getArrayResult();

        $accountIds = array_values(array_unique(
            array_map(static fn(array $r) => (int)$r['account_id'], $rows)
        ));

        if (!$accountIds) {
            $io->success('Жодного відкритого фото-блокування не знайдено.');
            return Command::SUCCESS;
        }

        $accounts = $this->accountRepository->findBy(['id' => $accountIds]);

        $unblocked = 0;
        $skippedDebt = 0;
        $skippedAlreadyActive = 0;
        $notified = 0;

        foreach ($accounts as $account) {
            /** @var Account $account */
            if ($account->isActive() === true) {
                $skippedAlreadyActive++;
                continue;
            }
            if ($this->debtPolicy->isAccountBlocked($account)) {
                $skippedDebt++;
                $io->writeln(sprintf(
                    '  [skip-debt] acc=%d (%s, кв.%s) debt=%s — не чіпаю, бо борг ще над порогом.',
                    $account->getId(),
                    $account->getAccountNumber(),
                    $account->getApartmentNumber(),
                    $account->getDebt(),
                ));
                continue;
            }

            if ($dryRun) {
                // NB: must NOT call forgiveBlockingRequests() here — it flushes
                // resolved_at, so a "dry" run would mutate the DB. Preview only.
                $io->writeln(sprintf(
                    '  [DRY] acc=%d (%s, кв.%s) — буде розблоковано (відкриті блокуючі запити будуть закриті).',
                    $account->getId(),
                    $account->getAccountNumber(),
                    $account->getApartmentNumber(),
                ));
                continue;
            }

            $forgiven = $this->photoService->forgiveBlockingRequests($account, $now);

            $account->setIsActive(true);
            $this->auditor->log(
                $account, false, true,
                AccountStatusLog::SOURCE_PHOTO_BULK_UNBLOCK,
                'photo',
                sprintf('soft-unblock (gentler rule): forgave %d open request(s)', $forgiven),
            );
            $this->em->flush();
            $unblocked++;

            $this->logger->info('photo:soft-unblock unblocked account', [
                'account_id' => $account->getId(),
                'account_number' => $account->getAccountNumber(),
                'forgiven_requests' => $forgiven,
            ]);

            foreach ($account->getUsers() as $user) {
                if (!$user->getChatId()) {
                    continue;
                }
                try {
                    $this->bot->sendMessage(
                        text: "✅ <b>Доступ до бронювання відновлено — вибачте за незручності.</b>\n\n"
                            . "Ваш акаунт розблоковано, робити нічого не потрібно.\n\n"
                            . "Нагадуємо: після кожного бронювання надсилайте одне фото альтанки в цей чат "
                            . "найближчим часом після завершення (протягом години) — і блокування не виникатиме. "
                            . "Дякуємо! 🙏",
                        chat_id: $user->getChatId(),
                        parse_mode: ParseMode::HTML,
                    );
                    $notified++;
                } catch (\Throwable $t) {
                    $this->logger->warning('photo:soft-unblock notification failed', [
                        'user_id' => $user->getId(),
                        'chat_id' => $user->getChatId(),
                        'error' => $t->getMessage(),
                    ]);
                }
            }
        }

        $io->success(sprintf(
            '%s Розблоковано: %d, пропущено (актив): %d, пропущено (борг): %d, повідомлень надіслано: %d',
            $dryRun ? '[DRY-RUN]' : 'Готово.',
            $unblocked,
            $skippedAlreadyActive,
            $skippedDebt,
            $notified,
        ));

        return Command::SUCCESS;
    }
}
