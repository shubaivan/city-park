<?php

namespace App\Command;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Service\ResidentChatService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remove somebody from the residents' chat, or let them back in.
 *
 * The gate closes the entry and not the exit: `mayJoin()` decides who gets in, and after
 * that the group is a group. Somebody who behaves badly in it has to be dealt with by
 * hand, and «by hand» in Telegram means one of two very different things:
 *
 * - **kick** (`--kick`) — ban and immediately unban. The person is removed and may ask to
 *   join again, which the gate will approve if they are still a resident. This is the
 *   right tool for "leave and cool off" and for an ex-owner who sold the flat.
 * - **ban** (default) — they stay out until somebody runs this with `--unban`. Telegram
 *   will not even deliver a join request from them, so the gate never sees them again.
 *
 * The person is identified the way the accountant knows them: a phone, an особовий
 * рахунок, a @username or a Telegram id — whichever is at hand when the complaint arrives.
 * Nothing is guessed: if the argument matches two people the command shows both and stops.
 */
#[AsCommand(
    name: 'resident-chat:ban',
    description: 'Remove a person from the residents\' chat (or lift it with --unban)',
)]
class ResidentChatBanCommand extends Command
{
    public function __construct(
        private Nutgram $bot,
        private ResidentChatService $residentChat,
        private TelegramUserRepository $users,
        private EntityManagerInterface $em,
        private LoggerInterface $chatLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('who', InputArgument::REQUIRED, 'Phone, особовий рахунок, @username or Telegram id')
            ->addOption('kick', null, InputOption::VALUE_NONE, 'Remove but allow them to request again')
            ->addOption('unban', null, InputOption::VALUE_NONE, 'Lift an earlier ban')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Written into the log next to who did it')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Also tell the person, in a DM from the bot')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Find the person and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->residentChat->isConfigured()) {
            $io->error('RESIDENT_CHAT_ID is empty — there is no chat to remove anybody from.');

            return Command::FAILURE;
        }

        $needle = trim((string)$input->getArgument('who'));
        $found = $this->find($needle);

        if ($found === []) {
            $io->error(sprintf('Нікого не знайшов за «%s». Спробуйте телефон, особовий рахунок, @username або Telegram id.', $needle));

            return Command::FAILURE;
        }

        if (count($found) > 1) {
            $io->warning(sprintf('За «%s» знайшлося кілька людей — уточніть:', $needle));
            $io->listing(array_map(fn (TelegramUser $u): string => $this->describe($u), $found));

            return Command::FAILURE;
        }

        $user = $found[0];
        $io->writeln('Знайдено: ' . $this->describe($user));

        $telegramId = (int)$user->getTelegramId();

        if ($telegramId === 0) {
            $io->error('У цієї людини немає Telegram id — вона ніколи не відкривала бота.');

            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $io->note('--dry-run: нічого не зроблено.');

            return Command::SUCCESS;
        }

        $chatId = (int)$this->residentChat->chatId();
        $action = match (true) {
            (bool)$input->getOption('unban') => 'unban',
            (bool)$input->getOption('kick') => 'kick',
            default => 'ban',
        };

        try {
            if ($action === 'unban') {
                // only_if_banned, so this cannot accidentally kick a current member.
                $this->bot->unbanChatMember($chatId, $telegramId, only_if_banned: true);
            } else {
                $this->bot->banChatMember($chatId, $telegramId);

                if ($action === 'kick') {
                    $this->bot->unbanChatMember($chatId, $telegramId, only_if_banned: true);
                }
            }
        } catch (\Throwable $e) {
            $io->error('Telegram відмовив: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $reason = trim((string)$input->getOption('reason'));

        $this->chatLogger->warning('resident chat moderation', [
            'action' => $action,
            'telegram_id' => $telegramId,
            'user_id' => $user->getId(),
            'account' => $user->getAccount()?->getAccountNumber(),
            'reason' => $reason !== '' ? $reason : null,
        ]);

        if ($input->getOption('notify')) {
            $this->tell($user, $action, $reason, $io);
        }

        $io->success(match ($action) {
            'unban' => 'Знято. Тепер людина може подати заявку на вступ ще раз.',
            'kick' => 'Видалено з чату. Може подати заявку знову — бот пустить, якщо вона й далі мешканець.',
            default => 'Заблоковано. Заявки від цієї людини Telegram більше не пропустить; зняти — цією ж командою з --unban.',
        });

        return Command::SUCCESS;
    }

    /** @return TelegramUser[] */
    private function find(string $needle): array
    {
        $digits = preg_replace('/\D+/', '', $needle);
        $found = [];

        if (str_starts_with($needle, '@')) {
            $found = $this->users->findBy(['username' => ltrim($needle, '@')]);
        }

        if ($found === [] && $digits !== '') {
            // An особовий рахунок is six digits and a phone is twelve, so the two never
            // collide; a Telegram id is nine or ten and is matched as itself.
            $qb = $this->em->createQueryBuilder()
                ->select('u')
                ->from(TelegramUser::class, 'u')
                ->leftJoin('u.account', 'a')
                ->where('u.telegram_id = :d OR a.account_number = :d')
                ->setParameter('d', $digits);

            $found = $qb->getQuery()->getResult();

            if ($found === []) {
                foreach ($this->users->findAll() as $candidate) {
                    if (preg_replace('/\D+/', '', (string)$candidate->getPhoneNumber()) === $digits) {
                        $found[] = $candidate;
                    }
                }
            }
        }

        return array_values($found);
    }

    private function describe(TelegramUser $user): string
    {
        $account = $user->getAccount();

        return trim(sprintf(
            '#%d %s %s · %s · %s',
            (int)$user->getId(),
            (string)$user->getFirstName(),
            (string)$user->getLastName(),
            $user->getPhoneNumber() ?: 'без телефона',
            $account ? $account->getPlaceLabel() . ' (о/р ' . $account->getAccountNumber() . ')' : 'без квартири',
        ));
    }

    /** Saying why beats leaving somebody to discover the door is shut. */
    private function tell(TelegramUser $user, string $action, string $reason, SymfonyStyle $io): void
    {
        $text = match ($action) {
            'unban' => '✅ Вас знову впустять до чату мешканців — надішліть заявку на вступ ще раз.',
            'kick' => '⚠️ Вас видалено з чату мешканців ЖК.',
            default => '⛔ Вас заблоковано в чаті мешканців ЖК.',
        };

        if ($reason !== '') {
            $text .= "\n\nПричина: " . $reason;
        }

        try {
            $this->bot->sendMessage(text: $text, chat_id: (int)$user->getChatId());
        } catch (\Throwable $e) {
            $io->warning('Повідомити людину не вдалося: ' . $e->getMessage());
        }
    }
}
