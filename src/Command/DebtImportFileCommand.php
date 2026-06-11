<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\AccountStatusLog;
use App\Repository\AccountRepository;
use App\Service\AccountStatusAuditor;
use App\Service\DebtPolicy;
use App\Service\PavilionPhotoService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

#[AsCommand(
    name: 'debt:import-file',
    description: 'Import a debt xlsx file from the CLI (same logic as /admin/debt upload). Pass a path to the xlsx as the first argument.',
)]
class DebtImportFileCommand extends Command
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
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the debt xlsx file');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and plan without writing or sending notifications.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string)$input->getArgument('path');
        $dryRun = (bool)$input->getOption('dry-run');

        if (!is_file($path) || !is_readable($path)) {
            $io->error('Файл не існує або недоступний: ' . $path);
            return Command::FAILURE;
        }

        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        $debtData = [];
        foreach ($worksheet->getRowIterator(3) as $row) {
            $r = $row->getRowIndex();
            $acc = $worksheet->getCell('B' . $r)->getValue();
            $debt = $worksheet->getCell('C' . $r)->getValue();
            if ($acc === null || $debt === null) continue;
            $acc = trim((string)$acc);
            if ($acc === '' || $acc === 'Сума:') continue;
            $debtData[$acc] = (float)$debt;
        }

        $io->writeln(sprintf('Опрацьовано рядків з файлу: %d', count($debtData)));

        $toBlock = []; $toUnblock = []; $debtUpdate = []; $notFound = [];

        foreach ($debtData as $accNum => $debt) {
            $account = $this->accountRepository->findOneBy(['account_number' => $accNum]);
            if (!$account) { $notFound[] = $accNum; continue; }

            $oldDebt = (float)($account->getDebt() ?? 0);
            $wasActive = $account->isActive() === true;
            $threshold = $this->debtPolicy->getThresholdFor($account);

            if ($oldDebt != $debt) {
                $debtUpdate[] = [$account, $oldDebt, $debt];
            }

            if ($debt > $threshold && $wasActive) {
                $toBlock[] = ['account' => $account, 'debt' => $debt, 'threshold' => $threshold];
            } elseif ($debt <= $threshold && !$wasActive) {
                $toUnblock[] = ['account' => $account, 'debt' => $debt, 'threshold' => $threshold];
            }
        }

        // Reset rule: accounts present in DB but NOT in file → debt=0, is_active=true.
        // Only touches accounts that previously had debt > 0 (preserves admin-paused at 0).
        $uploaded = array_map('strval', array_keys($debtData));
        $resetCandidates = [];
        foreach ($this->accountRepository->findAll() as $account) {
            if (in_array($account->getAccountNumber(), $uploaded, true)) continue;
            $hadDebt = $account->getDebt() !== null && (float)$account->getDebt() > 0;
            if (!$hadDebt) continue;
            $resetCandidates[] = $account;
        }

        $io->section('План змін');
        $io->writeln(sprintf('• Borg-cums to update: %d', count($debtUpdate)));
        $io->writeln(sprintf('• To block: %d', count($toBlock)));
        $io->writeln(sprintf('• To unblock: %d', count($toUnblock)));
        $io->writeln(sprintf('• To reset (not in file): %d', count($resetCandidates)));
        $io->writeln(sprintf('• Not found in DB: %d', count($notFound)));

        foreach ($toBlock as $r) {
            $io->writeln(sprintf('  [BLOCK]   acc=%s кв.%s debt=%.2f > поріг=%.2f',
                $r['account']->getAccountNumber(), $r['account']->getApartmentNumber(), $r['debt'], $r['threshold']));
        }
        foreach ($toUnblock as $r) {
            $io->writeln(sprintf('  [UNBLOCK] acc=%s кв.%s debt=%.2f <= поріг=%.2f',
                $r['account']->getAccountNumber(), $r['account']->getApartmentNumber(), $r['debt'], $r['threshold']));
        }

        if ($dryRun) {
            $io->success('[DRY-RUN] Жодних змін не записано.');
            return Command::SUCCESS;
        }

        // Apply changes
        $blocked = $unblocked = $reset = $notified = 0;

        foreach ($debtData as $accNum => $debt) {
            $account = $this->accountRepository->findOneBy(['account_number' => $accNum]);
            if (!$account) continue;
            $account->setDebt((string)$debt);

            $threshold = $this->debtPolicy->getThresholdFor($account);
            $wasActive = $account->isActive() === true;

            if ($debt > $threshold) {
                $account->setIsActive(false);
                if ($wasActive) {
                    $this->auditor->log(
                        $account, true, false,
                        AccountStatusLog::SOURCE_DEBT_IMPORT,
                        'debt',
                        sprintf('debt=%.2f, threshold=%.2f, source=%s', $debt, $threshold, basename($path)),
                    );
                    $blocked++;
                    foreach ($account->getUsers() as $user) {
                        if (!$user->getChatId()) continue;
                        try {
                            $this->bot->sendMessage(
                                text: sprintf(
                                    "🚫 Вас <b>ЗАБЛОКОВАНО</b> через борг: <b>%s грн</b>\n\n"
                                    . "Персональний поріг для вашої квартири: <b>%s грн</b>\n"
                                    . "<i>(площа × тариф ОСББ × 1.5 = 150%% місячної плати)</i>\n\n"
                                    . "Сплатіть заборгованість, щоб поновити доступ до бронювання.",
                                    number_format($debt, 2, '.', ' '),
                                    number_format($threshold, 2, '.', ' ')
                                ),
                                chat_id: $user->getChatId(),
                                parse_mode: ParseMode::HTML,
                            );
                            $notified++;
                        } catch (\Throwable $e) {
                            $this->logger->warning('debt:import-file block-notify failed', ['user_id' => $user->getId(), 'error' => $e->getMessage()]);
                        }
                    }
                }
            } else {
                if (!$wasActive && $this->photoService->hasOpenBlockingRequest($account)) {
                    // Debt within threshold, but a standing photo block keeps the account
                    // down until an admin clears it. Update the debt, leave is_active=false.
                    $this->logger->info('debt:import-file: debt OK but kept blocked by open photo request', [
                        'account_id' => $account->getId(),
                    ]);
                } elseif (!$wasActive) {
                    $account->setIsActive(true);
                    $this->auditor->log(
                        $account, false, true,
                        AccountStatusLog::SOURCE_DEBT_IMPORT,
                        'debt',
                        sprintf('debt=%.2f, threshold=%.2f, source=%s', $debt, $threshold, basename($path)),
                    );
                    $unblocked++;
                    foreach ($account->getUsers() as $user) {
                        if (!$user->getChatId()) continue;
                        try {
                            $this->bot->sendMessage(
                                text: sprintf(
                                    "✅ <b>Доступ до бронювання відновлено</b>.\n\n"
                                    . "Ваш поточний борг <b>%s грн</b> тепер у межах персонального порогу <b>%s грн</b>\n"
                                    . "<i>(площа × тариф ОСББ × 1.5 = 150%% місячної плати)</i>.\n\n"
                                    . "Можна знову бронювати.",
                                    number_format($debt, 2, '.', ' '),
                                    number_format($threshold, 2, '.', ' ')
                                ),
                                chat_id: $user->getChatId(),
                                parse_mode: ParseMode::HTML,
                            );
                            $notified++;
                        } catch (\Throwable $e) {
                            $this->logger->warning('debt:import-file unblock-notify failed', ['user_id' => $user->getId(), 'error' => $e->getMessage()]);
                        }
                    }
                } else {
                    $account->setIsActive(true);
                }
            }
            $this->em->persist($account);
        }

        foreach ($resetCandidates as $account) {
            $wasInactive = !$account->isActive();
            $account->setDebt('0');

            // Reset the debt, but a standing photo block survives the reset (admin-only release).
            $keepBlockedByPhoto = $wasInactive && $this->photoService->hasOpenBlockingRequest($account);
            if (!$keepBlockedByPhoto) {
                $account->setIsActive(true);
                if ($wasInactive) {
                    $this->auditor->log(
                        $account, false, true,
                        AccountStatusLog::SOURCE_DEBT_IMPORT,
                        'debt',
                        sprintf('reset (not in file %s)', basename($path)),
                    );
                }
            } else {
                $this->logger->info('debt:import-file: debt reset but kept blocked by open photo request', [
                    'account_id' => $account->getId(),
                ]);
            }
            $this->em->persist($account);
            $reset++;

            if ($wasInactive && !$keepBlockedByPhoto) {
                foreach ($account->getUsers() as $user) {
                    if (!$user->getChatId()) continue;
                    try {
                        $this->bot->sendMessage(
                            text: "✅ Ваш борг <b>погашено</b>. Доступ до бронювання відновлено!",
                            chat_id: $user->getChatId(),
                            parse_mode: ParseMode::HTML,
                        );
                        $notified++;
                    } catch (\Throwable $e) {
                        $this->logger->warning('debt:import-file reset-notify failed', ['user_id' => $user->getId(), 'error' => $e->getMessage()]);
                    }
                }
            }
        }

        $this->em->flush();

        $this->logger->info('debt:import-file applied', [
            'parsed' => count($debtData),
            'blocked' => $blocked,
            'unblocked' => $unblocked,
            'reset' => $reset,
            'not_found' => count($notFound),
            'notified' => $notified,
            'source' => $path,
        ]);

        $io->success(sprintf(
            'Готово. Опрацьовано: %d, заблоковано: %d, розблоковано: %d, скинуто борг: %d, не знайдено: %d, повідомлень: %d',
            count($debtData), $blocked, $unblocked, $reset, count($notFound), $notified
        ));
        return Command::SUCCESS;
    }
}
