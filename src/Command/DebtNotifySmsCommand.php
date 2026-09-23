<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\SmsLog;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;
use App\Service\SmsSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SMS to the flats that owe, for the ones the bot cannot otherwise tell.
 *
 * The board, the monthly DM and the chat post all reach residents **through Telegram**,
 * and on 21.09.2026 that meant 83 of 531 objects with a debt. The 77 objects owing more
 * than 5 000 грн — 815 788 грн between them — had three people in the bot. This is the
 * channel for the rest.
 *
 * **Who gets it is a choice, not a rule.** `--audience=all` writes to every debtor whose
 * number we have, which is what the head of the ОСББ asked for; `--audience=unreachable`
 * writes only to those Telegram does not reach, which costs a fraction and is there for
 * when the bill matters more than the reach. The default is hers.
 *
 * **Always run `--dry-run` first.** It prices the run and fills the journal with the rows
 * it would have sent, so the list can be read before a hryvnia is spent.
 */
#[AsCommand(name: 'debt:notify-sms', description: 'SMS to debtors above a threshold')]
class DebtNotifySmsCommand extends Command
{
    /** Иван's and the ОСББ's figure: above this a debt is worth paying to chase. */
    private const DEFAULT_MIN_DEBT = 5000.0;

    public function __construct(
        private AccountRepository $accounts,
        private ExpectedResidentRepository $expected,
        private SmsSender $sms,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Мінімальний борг, грн', (string)self::DEFAULT_MIN_DEBT)
            ->addOption('audience', null, InputOption::VALUE_REQUIRED, 'all | unreachable', 'all')
            ->addOption('price', null, InputOption::VALUE_REQUIRED, 'Ціна за одну SMS, грн', '0.98')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Порахувати і показати, нічого не надсилати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $min = (float)$input->getOption('min');
        $audience = (string)$input->getOption('audience');
        $price = (float)$input->getOption('price');
        $dryRun = (bool)$input->getOption('dry-run');

        if (!in_array($audience, ['all', 'unreachable'], true)) {
            $io->error('--audience приймає тільки all або unreachable');

            return Command::INVALID;
        }

        if (!$dryRun && !$this->sms->isConfigured()) {
            $io->error('TURBOSMS_TOKEN не налаштовано — надсилати нема чим. Спробуйте --dry-run.');

            return Command::FAILURE;
        }

        $balance = $this->sms->balance();
        $io->writeln(sprintf(
            'Баланс: %s · відправник: %s · поріг: %s грн · аудиторія: %s%s',
            $balance === null ? 'невідомий' : number_format($balance, 2, '.', ' ') . ' грн',
            $this->sms->senderName() !== '' ? $this->sms->senderName() : 'загальний',
            number_format($min, 2, '.', ' '),
            $audience,
            $dryRun ? ' · DRY RUN' : '',
        ));

        $sent = $skippedTelegram = $noPhone = $failed = 0;
        $parts = 0;

        foreach ($this->accounts->findAll() as $account) {
            if (!$account instanceof Account || (float)($account->getDebt() ?? 0) < $min) {
                continue;
            }

            $reachable = $this->reachableInTelegram($account);

            if ($audience === 'unreachable' && $reachable) {
                $skippedTelegram++;
                continue;
            }

            $phone = $this->phoneFor($account);
            if ($phone === null) {
                $noPhone++;
                continue;
            }

            $text = $this->text($account);

            $log = $this->sms->send(
                $phone,
                $text,
                SmsLog::PURPOSE_DEBT,
                $account,
                null,
                'debt:notify-sms',
                $dryRun,
            );

            $parts += $log->getParts();

            if ($log->isFailed()) {
                $failed++;
                $io->writeln(sprintf('  <fg=red>✗</> %s — %s', $account->getPlaceLabel(), $log->getError()));
                continue;
            }

            $sent++;
            $io->writeln(sprintf(
                '  <fg=green>%s</> %s · %s грн · %d SMS',
                $dryRun ? '·' : '✓',
                $account->getPlaceLabel(),
                number_format((float)$account->getDebt(), 2, '.', ' '),
                $log->getParts(),
            ));
        }

        $io->newLine();
        $io->writeln(sprintf(
            '%s: %d · пропущено (є в Telegram): %d · без номера: %d · помилок: %d',
            $dryRun ? 'Надіслали б' : 'Надіслано',
            $sent,
            $skippedTelegram,
            $noPhone,
            $failed,
        ));
        $io->writeln(sprintf(
            'Частин SMS: %d · орієнтовна вартість: %s грн',
            $parts,
            number_format($parts * $price, 2, '.', ' '),
        ));

        if ($noPhone > 0) {
            $io->note(sprintf(
                '%d об’єктів із боргом не мають жодного номера — ні мешканця в боті, ні записаного '
                . 'в «Очікуємо в боті». Саме вони і є причина просити в ОСББ базу власників.',
                $noPhone,
            ));
        }

        return Command::SUCCESS;
    }

    /** Somebody on this account whom the bot can write to for free. */
    private function reachableInTelegram(Account $account): bool
    {
        foreach ($account->getUsers() as $user) {
            if ($user->getChatId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A number for this flat, from whichever half of the register has one.
     *
     * A linked resident's own number first — it was confirmed by them sharing it with the
     * bot — and otherwise whatever the ОСББ wrote down against the object, which is the
     * only thing there is for the ~790 objects with nobody in the bot.
     */
    private function phoneFor(Account $account): ?string
    {
        foreach ($account->getUsers() as $user) {
            if ($user->getPhoneNumber()) {
                return $user->getPhoneNumber();
            }
        }

        foreach ($this->expected->forAccount($account) as $expected) {
            if ($expected->getPhone() !== '') {
                return $expected->getPhone();
            }
        }

        return null;
    }

    /**
     * One SMS, and it has to stay one.
     *
     * 70 Cyrillic characters is the whole budget, so this says three things and stops:
     * who is writing, how much, and where to look. The sum is printed without kopecks —
     * «16314» is the same information as «16 314,49» to somebody deciding whether to pay,
     * and the spaces and the comma cost four characters that the link needs.
     */
    private function text(Account $account): string
    {
        return sprintf(
            'ОСББ Сіті Парк: борг %d грн. Деталі у боті t.me/che_city_park_bot',
            (int)round((float)($account->getDebt() ?? 0)),
        );
    }
}
