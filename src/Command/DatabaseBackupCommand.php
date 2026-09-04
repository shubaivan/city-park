<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

/**
 * Dump the database and send it somewhere that is not this server.
 *
 * The whole house lives in this database and nowhere else: 449 people, who lives in which
 * flat, every phone number the ОСББ has, the arrears, the bookings, the complaints. None of
 * it is reconstructible — the debt file is the accountant's and only covers debts, and the
 * resident↔flat mapping exists purely because Аліна built it one person at a time over
 * months.
 *
 * A copy kept on the same machine protects against a bad migration and nothing else. So the
 * dump is *delivered*: `BACKUP_TELEGRAM_CHAT_ID` is a chat the bot can reach, normally the
 * owner's own, which needs no SMTP credentials, survives the server entirely and is
 * readable from a phone in a queue somewhere. A copy stays on disk too (`--keep`), because
 * the common case is still "I need yesterday's data, right now".
 *
 * The dump is plain `pg_dump | gzip`, restorable with nothing but psql:
 *
 *     gunzip -c city-park-20260904-030000.sql.gz | psql -d db_city
 *
 * **The file contains personal data**, so it goes to one configured chat and never to a
 * group. Set `BACKUP_PASSPHRASE` to have it encrypted first (AES-256 via openssl); then
 * restoring needs `openssl enc -d -aes-256-cbc -pbkdf2 -in <file> | gunzip | psql`.
 */
#[AsCommand(
    name: 'db:backup',
    description: 'Dump the database, keep the last N locally and deliver it off the server',
)]
class DatabaseBackupCommand extends Command
{
    /** Enough history to notice a problem a week late; each dump is ~70 KB gzipped. */
    private const DEFAULT_KEEP = 14;

    public function __construct(
        private Connection $connection,
        private Nutgram $bot,
        private LoggerInterface $logger,
        private TransportInterface $mailTransport,
        private string $projectDir,
        private string $backupChatId = '',
        private string $backupEmail = '',
        private string $mailerDsn = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'How many dumps to keep on disk', (string)self::DEFAULT_KEEP)
            ->addOption('no-send', null, InputOption::VALUE_NONE, 'Write the dump but do not deliver it anywhere')
            ->addOption('passphrase', null, InputOption::VALUE_REQUIRED, 'Encrypt with this passphrase (defaults to BACKUP_PASSPHRASE)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dir = rtrim($this->projectDir, '/') . '/var/backups';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $io->error('Cannot create ' . $dir);

            return Command::FAILURE;
        }

        $params = $this->connection->getParams();
        $database = (string)($params['dbname'] ?? '');

        if ($database === '') {
            $io->error('No database name in the connection parameters');

            return Command::FAILURE;
        }

        $stamp = (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Ymd-His');
        $passphrase = (string)($input->getOption('passphrase') ?? '') ?: (string)getenv('BACKUP_PASSPHRASE');
        $path = sprintf('%s/city-park-%s.sql.gz%s', $dir, $stamp, $passphrase !== '' ? '.enc' : '');

        // The password goes in the environment, never on the command line: `ps` is readable
        // by every user on the box, and this one opens the whole resident base.
        $command = sprintf(
            'pg_dump -h %s -p %s -U %s -d %s --no-owner --no-privileges | gzip -9%s > %s',
            escapeshellarg((string)($params['host'] ?? '127.0.0.1')),
            escapeshellarg((string)($params['port'] ?? 5432)),
            escapeshellarg((string)($params['user'] ?? '')),
            escapeshellarg($database),
            $passphrase !== '' ? ' | openssl enc -aes-256-cbc -pbkdf2 -pass env:BACKUP_PASSPHRASE' : '',
            escapeshellarg($path),
        );

        $process = Process::fromShellCommandline($command, null, [
            'PGPASSWORD' => (string)($params['password'] ?? ''),
            'BACKUP_PASSPHRASE' => $passphrase,
        ], null, 600);

        $process->run();

        if (!$process->isSuccessful() || !is_file($path) || filesize($path) === 0) {
            @unlink($path);
            $io->error('pg_dump failed: ' . trim($process->getErrorOutput()));
            $this->logger->error('db:backup failed', ['error' => $process->getErrorOutput()]);

            return Command::FAILURE;
        }

        $size = (int)filesize($path);
        $io->success(sprintf('%s (%s)', $path, $this->humanSize($size)));

        $pruned = $this->prune($dir, max(1, (int)$input->getOption('keep')));

        if ($pruned > 0) {
            $io->writeln(sprintf('  видалено старих копій: %d', $pruned));
        }

        if ($input->getOption('no-send')) {
            return Command::SUCCESS;
        }

        $caption = $this->caption($size, $database, $passphrase !== '');
        $delivered = 0;
        $attempted = 0;

        if ($this->backupChatId !== '') {
            $attempted++;
            $delivered += $this->sendToTelegram($io, $path, $caption) ? 1 : 0;
        }

        if ($this->backupEmail !== '') {
            $attempted++;
            $delivered += $this->sendByEmail($io, $path, $caption) ? 1 : 0;
        }

        // A dump that never leaves the machine it protects is not a backup. Say so loudly
        // rather than exiting green — a silently local-only backup is exactly the state
        // somebody thinks they are protected in.
        if ($attempted === 0) {
            $io->warning('Ні BACKUP_TELEGRAM_CHAT_ID, ні BACKUP_EMAIL не налаштовані — копія лишилась ЛИШЕ на цьому сервері.');

            return Command::SUCCESS;
        }

        return $delivered > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function caption(int $size, string $database, bool $encrypted): string
    {
        $counts = $this->counts();

        return sprintf(
            "🗄 <b>Бекап бази</b> — %s\n\n"
                . "Розмір: %s%s\n"
                . "Акаунтів: %d · мешканців: %d · бронювань: %d\n\n"
                . '<i>Відновлення: gunzip -c &lt;файл&gt; | psql -d %s</i>',
            (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i'),
            $this->humanSize($size),
            $encrypted ? ' · зашифровано' : '',
            $counts['accounts'],
            $counts['users'],
            $counts['bookings'],
            $database,
        );
    }

    private function sendToTelegram(SymfonyStyle $io, string $path, string $caption): bool
    {
        try {
            $stream = fopen($path, 'rb');

            if ($stream === false) {
                throw new \RuntimeException('cannot read ' . $path);
            }

            $this->bot->sendDocument(
                document: InputFile::make($stream, basename($path)),
                chat_id: (int)$this->backupChatId,
                caption: $caption,
                parse_mode: ParseMode::HTML,
                disable_notification: true,
            );

            $io->success('Надіслано в Telegram.');
            $this->logger->info('db:backup delivered', [
                'file' => basename($path),
                'chat_id' => $this->backupChatId,
            ]);

            return true;
        } catch (\Throwable $e) {
            $io->error('Не вдалося надіслати в Telegram: ' . $e->getMessage());
            $this->logger->error('db:backup telegram delivery failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The second channel, and deliberately a different kind of thing.
     *
     * Telegram and the server can both be lost at once — the same person's phone, the same
     * account, the same afternoon. Mail goes through the project's MAILER_DSN to an address
     * nobody in this system controls, and Gmail keeps it searchable long after anyone has
     * stopped thinking about backups.
     */
    private function sendByEmail(SymfonyStyle $io, string $path, string $caption): bool
    {
        try {
            $email = (new Email())
                ->to($this->backupEmail)
                ->subject(sprintf(
                    'Бекап city-park — %s',
                    (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i'),
                ))
                ->text(strip_tags(str_replace(['<br>', '\n'], "\n", $caption)))
                ->attachFromPath($path);

            // Straight down the transport, not through MailerInterface.
            //
            // `SendEmailMessage` is routed to the async Messenger transport in this project,
            // so `mailer->send()` only *queues* — it returns success while the actual
            // delivery happens later in the worker, and a failure there is a CRITICAL in a
            // log nobody is reading. That is precisely the wrong shape for a backup, whose
            // whole value is knowing it left. Sending through the transport blocks until the
            // SMTP server has taken it, and the [OK] below means what it says.
            //
            // The From is the SMTP account itself; Gmail rewrites anything else anyway, and
            // guessing a sender address here is how mail silently lands in spam.
            $email->from($this->senderAddress());
            $this->mailTransport->send($email);

            $io->success('Надіслано на ' . $this->backupEmail);
            $this->logger->info('db:backup emailed', ['to' => $this->backupEmail]);

            return true;
        } catch (\Throwable $e) {
            $io->error('Не вдалося надіслати поштою: ' . $e->getMessage());
            $this->logger->error('db:backup email delivery failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The SMTP account's own address, taken from the DSN user.
     *
     * Sending through the transport skips the framework's default From, so one has to be
     * set here; Gmail rewrites anything that is not the authenticated account anyway.
     */
    private function senderAddress(): string
    {
        // From the bound MAILER_DSN, not getenv(): Symfony's Dotenv populates $_ENV and
        // $_SERVER but does not necessarily putenv(), so getenv() is empty as often as not.
        $user = parse_url($this->mailerDsn, PHP_URL_USER);
        $user = is_string($user) ? urldecode($user) : '';

        return str_contains($user, '@') ? $user : ($this->backupEmail ?: 'noreply@localhost');
    }

    /** @return array{accounts:int, users:int, bookings:int} */
    private function counts(): array
    {
        $count = function (string $table): int {
            try {
                return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
            } catch (\Throwable) {
                return 0;
            }
        };

        return [
            'accounts' => $count('account'),
            'users' => $count('telegram_user'),
            'bookings' => $count('scheduled_set'),
        ];
    }

    private function prune(string $dir, int $keep): int
    {
        $files = glob($dir . '/city-park-*.sql.gz*') ?: [];

        if (count($files) <= $keep) {
            return 0;
        }

        rsort($files);
        $pruned = 0;

        foreach (array_slice($files, $keep) as $old) {
            if (@unlink($old)) {
                $pruned++;
            }
        }

        return $pruned;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1f МБ', $bytes / 1048576)
            : sprintf('%.0f КБ', $bytes / 1024);
    }
}
