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

#[AsCommand(
    name: 'pavilion:photo:bulk-unblock',
    description: 'Resolve every open photo-blocked request, restore is_active for the affected accounts (when debt is OK), and notify the users via Telegram.',
)]
class PhotoBulkUnblockCommand extends Command
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

        $accountIds = array_map(static fn(array $r) => (int)$r['account_id'], $rows);

        // Also pick up accounts blocked manually after a missed photo whose obligation
        // already auto-resolved (e.g. user uploaded late) but is_active stays false.
        $resolvedRecently = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(r.account) AS account_id
             FROM App\Entity\PhotoUploadRequest r
             WHERE r.blocked_at IS NOT NULL'
        )->getArrayResult();

        foreach ($resolvedRecently as $row) {
            $accountIds[] = (int)$row['account_id'];
        }
        $accountIds = array_values(array_unique($accountIds));

        if (!$accountIds) {
            $io->success('Жодного фото-заблокованого акаунту не знайдено.');
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
                sprintf('forgave %d open request(s)', $forgiven),
            );
            $this->em->flush();
            $unblocked++;

            $this->logger->info('photo:bulk-unblock unblocked account', [
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
                        text: "✅ <b>Доступ до бронювання відновлено — і вибачте за незручності.</b>\n\n"
                            . "Через технічну помилку в боті ваші фото альтанки могли не зберігатися після надсилання, "
                            . "тому акаунт було помилково заблоковано. Це наша провина, а не ваша.\n\n"
                            . "Ми виправили помилку та розблокували ваш акаунт. Робити нічого не потрібно — "
                            . "надалі, як і раніше, просто надсилайте одне фото після завершення бронювання.\n\n"
                            . "Дякуємо за розуміння та ще раз вибачте! 🙏",
                        chat_id: $user->getChatId(),
                        parse_mode: ParseMode::HTML,
                    );
                    $notified++;
                } catch (\Throwable $t) {
                    $this->logger->warning('photo:bulk-unblock notification failed', [
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
