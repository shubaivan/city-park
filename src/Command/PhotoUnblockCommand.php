<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\AccountStatusLog;
use App\Entity\TelegramUser;
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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Single-account photo unblock for the "user asked, admin confirmed" case.
 *
 * Unlike pavilion:photo:bulk-unblock (which sweeps every blocked account and
 * sends the 2026-05-25 incident-apology text), this command:
 *   - targets exactly one account by its account number,
 *   - treats the original block as VALID (no "our fault" wording),
 *   - sends a neutral "access restored" notification.
 */
#[AsCommand(
    name: 'pavilion:photo:unblock',
    description: 'Unblock ONE account after a valid photo-miss block (user requested, admin confirmed): resolve its open photo requests, restore is_active when debt is OK, and notify the user with neutral wording.',
)]
class PhotoUnblockCommand extends Command
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
        $this
            ->addArgument('account', InputArgument::REQUIRED, 'Account number (особовий рахунок), e.g. 110003')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would happen without changing anything.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Unblock even if the account is also over its debt threshold.')
            ->addOption('no-notify', null, InputOption::VALUE_NONE, 'Do not send the Telegram notification.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountNumber = trim((string)$input->getArgument('account'));
        $dryRun = (bool)$input->getOption('dry-run');
        $force = (bool)$input->getOption('force');
        $noNotify = (bool)$input->getOption('no-notify');
        $now = SchedulePavilionService::createNewDate();

        $account = $this->accountRepository->findOneBy(['account_number' => $accountNumber]);
        if (!$account instanceof Account) {
            $io->error(sprintf('Акаунт з номером "%s" не знайдено.', $accountNumber));
            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            'Акаунт #%d (%s, кв.%s) — is_active=%s, debt=%s, поріг=%s',
            $account->getId(),
            $account->getAccountNumber(),
            $account->getApartmentNumber(),
            $account->isActive() ? 'true' : 'false',
            $account->getDebt(),
            $this->debtPolicy->getThresholdFor($account),
        ));

        if ($account->isActive() === true) {
            $io->success('Акаунт уже активний — нічого робити не потрібно.');
            return Command::SUCCESS;
        }

        if ($this->debtPolicy->isAccountBlocked($account) && !$force) {
            $io->error(sprintf(
                'Акаунт також заблокований за борг (debt=%s > поріг). Розблокування зніме і борговий блок. '
                . 'Якщо це навмисно — додайте --force; інакше спершу розберіться з боргом.',
                $account->getDebt(),
            ));
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note(sprintf(
                '[DRY] Акаунт #%d буде розблоковано; відкриті блокуючі фото-запити будуть закриті; '
                . 'користувач отримає нейтральне повідомлення%s.',
                $account->getId(),
                $noNotify ? ' (вимкнено --no-notify)' : '',
            ));
            return Command::SUCCESS;
        }

        $forgiven = $this->photoService->forgiveBlockingRequests($account, $now);

        $account->setIsActive(true);
        $this->auditor->log(
            $account, false, true,
            AccountStatusLog::SOURCE_ADMIN,
            'photo',
            sprintf('manual unblock (user requested), forgave %d open request(s)', $forgiven),
            'cli:photo-unblock',
        );
        $this->em->flush();

        $this->logger->info('photo:unblock unblocked account', [
            'account_id' => $account->getId(),
            'account_number' => $account->getAccountNumber(),
            'forgiven_requests' => $forgiven,
        ]);

        $notified = 0;
        if (!$noNotify) {
            $text = "✅ <b>Доступ до бронювання відновлено.</b>\n\n"
                . "Обмеження знято — можна знову бронювати.\n\n"
                . "<i>Нагадуємо: після кожного бронювання надсилайте одне фото альтанки в цей чат. "
                . "Зробити це можна протягом усього дня — акаунт блокується лише якщо фото немає "
                . "до 09:00 наступного ранку.</i>\n\n"
                . "Дякуємо!";

            foreach ($account->getUsers() as $user) {
                /** @var TelegramUser $user */
                if (!$user->getChatId()) {
                    continue;
                }
                try {
                    $this->bot->sendMessage(
                        text: $text,
                        chat_id: $user->getChatId(),
                        parse_mode: ParseMode::HTML,
                    );
                    $notified++;
                } catch (\Throwable $t) {
                    $this->logger->warning('photo:unblock notification failed', [
                        'user_id' => $user->getId(),
                        'chat_id' => $user->getChatId(),
                        'error' => $t->getMessage(),
                    ]);
                }
            }
        }

        $io->success(sprintf(
            'Готово. Акаунт #%d розблоковано, закрито запитів: %d, повідомлень надіслано: %d',
            $account->getId(),
            $forgiven,
            $notified,
        ));

        return Command::SUCCESS;
    }
}
