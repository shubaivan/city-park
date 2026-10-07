<?php

namespace App\Command;

use App\Entity\Account;
use App\Service\DebtSmsCampaign;
use App\Service\SmsSender;
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
    public function __construct(
        private DebtSmsCampaign $campaign,
        private SmsSender $sms,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Мінімальний борг, грн', (string)DebtSmsCampaign::DEFAULT_MIN_DEBT)
            ->addOption('audience', null, InputOption::VALUE_REQUIRED, 'all | unreachable', 'all')
            ->addOption('price', null, InputOption::VALUE_REQUIRED, 'Ціна за одну SMS, грн', (string)SmsSender::PRICE_PER_PART)
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

        $plan = $this->campaign->plan($min, $audience);

        foreach ($plan['tooLong'] as $account) {
            $io->writeln(sprintf('  <fg=red>✗</> %s — адреса задовга для однієї SMS', $account->getPlaceLabel()));
        }

        $run = $this->campaign->send($plan, 'debt:notify-sms', $dryRun);

        foreach ($run['logs'] as $log) {
            $account = $log->getAccount();
            if ($log->isFailed()) {
                $io->writeln(sprintf('  <fg=red>✗</> %s — %s', $account?->getPlaceLabel(), $log->getError()));
                continue;
            }

            $io->writeln(sprintf(
                '  <fg=green>%s</> %s · %s грн · %d SMS',
                $dryRun ? '·' : '✓',
                $account?->getPlaceLabel(),
                number_format((float)$account?->getDebt(), 2, '.', ' '),
                $log->getParts(),
            ));
        }

        $sent = $run['sent'];
        $parts = $run['parts'];
        $failed = $run['failed'] + count($plan['tooLong']);
        $skippedTelegram = $plan['skippedTelegram'];
        $noPhone = $plan['noPhone'];

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

    /**
     * One SMS, and it has to stay one — or nothing.
     *
     * Which object, how much, and the ask. Nothing else (Иван, 30.09.2026): no greeting
     * and no link to the bot. The sender line already says «CityPark», so the text does not
     * need to say who is writing, and every character saved is one that keeps a long label
     * — «паркомісце 138» with a five-digit sum — inside a single part. The object leads
     * because a household with a flat and a parking space has to know which one owes.
     *
     * Null when even this does not fit, never a second part — SmsSender refuses one anyway.
     */
    public static function text(Account $account): ?string
    {
        $house = trim((string)$account->getHouseNumber());
        $unit = str_replace('кв. ', 'кв.', $account->getUnitLabel());
        $place = $house === '' ? $unit : sprintf('Буд.%s %s', $house, $unit);
        $place = mb_strtoupper(mb_substr($place, 0, 1)) . mb_substr($place, 1);

        $text = sprintf('%s: борг %d грн. Просимо сплатити.', $place, (int)round((float)($account->getDebt() ?? 0)));

        return SmsSender::parts($text) === 1 ? $text : null;
    }
}
