<?php

namespace App\Command;

use App\Entity\SmsLog;
use App\Service\SmsSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Does the SMS channel work at all — token, balance, sender name, one real message.
 *
 * It exists so that the minute a token is pasted into `.env.local` there is exactly one
 * command between that and knowing. The alternative is finding out during the first real
 * run, against 77 debtors, which is the worst possible place to discover that the sender
 * name is still unregistered or the balance is 13 грн.
 */
#[AsCommand(name: 'sms:test', description: 'Check the SMS token, balance and sender; optionally send one message')]
class SmsTestCommand extends Command
{
    public function __construct(private SmsSender $sms)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('phone', InputArgument::OPTIONAL, 'Номер для тестової SMS (без нього — лише перевірка)')
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'Текст', 'ОСББ Сіті Парк: перевірка звязку. Це тестове повідомлення.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->sms->isConfigured()) {
            $io->error('TURBOSMS_TOKEN порожній. Додайте його у .env.local і повторіть.');

            return Command::FAILURE;
        }

        $io->success('Токен на місці.');

        $balance = $this->sms->balance();
        if ($balance === null) {
            $io->warning('Баланс дізнатися не вдалося — токен може бути невірним або немає мережі.');
        } else {
            $io->writeln(sprintf('Баланс: <info>%s грн</info>', number_format($balance, 2, '.', ' ')));
        }

        $sender = $this->sms->senderName();
        $io->writeln(sprintf(
            'Відправник: <info>%s</info>%s',
            $sender !== '' ? $sender : 'загальний',
            $sender !== '' ? ' (якщо ще не зареєстрований в операторів — надсилання з ним відхилять)' : '',
        ));

        $phone = $input->getArgument('phone');
        if (!$phone) {
            $io->note('Номер не вказано — нічого не надсилав. Щоб надіслати: bin/console sms:test 380XXXXXXXXX');

            return Command::SUCCESS;
        }

        $text = (string)$input->getOption('text');
        $parts = SmsSender::parts($text);
        $io->writeln(sprintf('Текст: %d символів — <info>%d SMS</info>', mb_strlen($text), $parts));

        $log = $this->sms->send($phone, $text, SmsLog::PURPOSE_TEST, null, null, 'sms:test');

        if ($log->isFailed()) {
            $io->error('Не надіслано: ' . $log->getError());

            return Command::FAILURE;
        }

        $io->success(sprintf('Надіслано. ID у провайдера: %s', $log->getProviderId() ?? '—'));

        return Command::SUCCESS;
    }
}
