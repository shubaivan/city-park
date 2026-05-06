<?php

namespace App\Command;

use App\Repository\AccountRepository;
use App\Service\DebtPolicy;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'DebtNotifyCommand',
    description: 'Send debt notifications to users on 15th of each month',
)]
class DebtNotifyCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private AccountRepository $accountRepository,
        private Nutgram $bot,
        private DebtPolicy $debtPolicy,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $accounts = $this->accountRepository->findAll();
            $notified = 0;

            foreach ($accounts as $account) {
                if (!$this->debtPolicy->isAccountBlocked($account)) {
                    continue;
                }

                $debt = number_format((float)$account->getDebt(), 2, '.', ' ');

                foreach ($account->getUsers() as $user) {
                    if (!$user->getChatId()) {
                        continue;
                    }

                    $this->bot->sendMessage(
                        text: sprintf(
                            "📢 <b>Повідомлення про заборгованість</b>\n\nОсобовий рахунок: <b>%s</b>\nСума боргу: <b>%s грн</b>\n\n⚠️ Наявність боргу блокує можливість бронювання альтанок.\nБудь ласка, сплатіть заборгованість.",
                            $account->getAccountNumber(),
                            $debt
                        ),
                        chat_id: $user->getChatId(),
                        parse_mode: ParseMode::HTML
                    );

                    $notified++;
                    $this->logger->info(sprintf(
                        'Debt notification sent to user %s (chat_id: %s), debt: %s',
                        $user->getTelegramId(),
                        $user->getChatId(),
                        $debt
                    ));
                }
            }

            $io->success(sprintf('Debt notifications sent: %d', $notified));
        } catch (\Throwable $t) {
            $this->logger->error('DebtNotifyCommand error: ' . $t->getMessage());
            $io->error($t->getMessage());
        }

        return Command::SUCCESS;
    }
}
