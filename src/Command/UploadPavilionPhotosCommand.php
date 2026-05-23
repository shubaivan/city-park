<?php

namespace App\Command;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uploads the two static pavilion images to Telegram once and prints back the
 * file_ids so they can be put in .env.local as PAVILION{1,2}_PHOTO_FILE_ID.
 *
 * Without this, every booking confirmation re-uploads the JPEG (~1-3s inside
 * /hook), which pushes Telegram past its webhook retry timeout and causes the
 * same callback_query to be delivered twice.
 */
#[AsCommand(
    name: 'bot:upload-pavilion-photos',
    description: 'Pre-upload pavilion1/pavilion2 to Telegram and print their file_ids.',
)]
class UploadPavilionPhotosCommand extends Command
{
    public function __construct(
        private Nutgram $bot,
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('chat_id', InputArgument::REQUIRED, 'Telegram chat id to receive the warmup uploads (your own user id works).')
            ->addOption('keep-messages', null, InputOption::VALUE_NONE, 'Skip deleting the warmup messages after capturing file_id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chatId = (int) $input->getArgument('chat_id');
        $keep = (bool) $input->getOption('keep-messages');

        $results = [];
        foreach ([1, 2] as $pavilion) {
            $file = sprintf('%s/assets/img/pavilion%d', $this->projectDir, $pavilion);
            if (!is_file($file) || !is_readable($file)) {
                $io->error(sprintf('Pavilion %d: source file missing at %s', $pavilion, $file));
                return Command::FAILURE;
            }

            // Open read-only — InputFile::make() with a string path uses rb+,
            // which fails on 0644 root-owned assets when running as www-data.
            $stream = fopen($file, 'rb');
            if ($stream === false) {
                $io->error(sprintf('Pavilion %d: cannot open %s', $pavilion, $file));
                return Command::FAILURE;
            }
            try {
                $msg = $this->bot->sendPhoto(
                    chat_id: $chatId,
                    photo: InputFile::make($stream, sprintf('pavilion%d.jpg', $pavilion)),
                    caption: sprintf('warmup pavilion%d', $pavilion),
                );
            } catch (\Throwable $e) {
                $io->error(sprintf('Pavilion %d upload failed: %s', $pavilion, $e->getMessage()));
                return Command::FAILURE;
            }

            $sizes = $msg->photo ?? [];
            $largest = $sizes ? end($sizes) : null;
            $fileId = $largest->file_id ?? null;
            if (!is_string($fileId) || $fileId === '') {
                $io->error(sprintf('Pavilion %d: Telegram returned no file_id', $pavilion));
                return Command::FAILURE;
            }
            $results[$pavilion] = $fileId;

            if (!$keep && isset($msg->message_id)) {
                try {
                    $this->bot->deleteMessage($chatId, $msg->message_id);
                } catch (\Throwable) {
                    // best-effort cleanup
                }
            }
        }

        $io->success('Captured file_ids. Paste these into .env.local on the server:');
        $output->writeln('');
        $output->writeln(sprintf('PAVILION1_PHOTO_FILE_ID=%s', $results[1]));
        $output->writeln(sprintf('PAVILION2_PHOTO_FILE_ID=%s', $results[2]));
        $output->writeln('');
        $io->note('After editing .env.local: rm -rf var/cache/prod && bin/console cache:warmup --env=prod && systemctl restart php8.3-fpm');

        return Command::SUCCESS;
    }
}
