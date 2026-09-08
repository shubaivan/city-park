<?php

namespace App\Service;

use App\Entity\TelegramUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * The residents' Telegram profile photos, cached for the panel.
 *
 * Аліна works from a table of phone numbers and Telegram nicknames; a face is the one
 * thing that turns «Ника, кв. 85» into somebody she has met. Asked for on 08.09.2026.
 *
 * **Fewer than half of them have one we may have.** `getUserProfilePhotos` returns nothing
 * when the person's «Фото профілю» is «Мої контакти» — the bot is not a contact, and no
 * API gets past that. Measured before building this: 36 of 80 linked residents. That is
 * the feature's ceiling and it is why the initials circle is not a fallback for a rare
 * case but the normal rendering for most rows.
 *
 * Two rules that are the whole reason this is a service and not four lines in a command:
 *
 * - **The cache lives in `var/avatars/`, never under `public/`.** A resident's face served
 *   by a guessable URL is published; served through `/admin/avatar/{id}` it is behind the
 *   same login as their phone number and their debt.
 * - **A photo that disappears from Telegram is deleted here.** Somebody who closes their
 *   profile has withdrawn it, and a copy that outlives the withdrawal is the panel keeping
 *   something the person took back.
 */
class AvatarService
{
    /** Re-asking Telegram about everybody every night buys nothing; people change these rarely. */
    public const STALE_AFTER_DAYS = 7;

    /** Telegram tolerates ~30 requests a second; this is three calls per person, unhurried. */
    public const PAUSE_MS = 120;

    public function __construct(
        private Nutgram $bot,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {}

    public function directory(): string
    {
        return $this->projectDir . '/var/avatars';
    }

    /** Absolute path of a cached avatar, or null when there is none on disk. */
    public function fileFor(TelegramUser $user): ?string
    {
        $path = $user->getPhotoPath();

        if ($path === null || $path === '') {
            return null;
        }

        // The stored value is a bare file name; anything else did not come from here.
        if (basename($path) !== $path) {
            return null;
        }

        $absolute = $this->directory() . '/' . $path;

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * Ask Telegram about one person and bring the cache in line with the answer.
     *
     * Returns what happened, for the command's summary: `saved`, `removed`, `none` or
     * `failed`. **Never throws** — one unreachable user must not stop a sync of 449.
     */
    public function sync(TelegramUser $user): string
    {
        $telegramId = (int)$user->getTelegramId();

        try {
            $photos = $telegramId > 0 ? $this->bot->getUserProfilePhotos($telegramId, limit: 1) : null;
            $sizes = $photos?->photos[0] ?? null;

            if (!$sizes) {
                return $this->forget($user) ? 'removed' : 'none';
            }

            // Telegram sends the same picture in several sizes, smallest first. The panel
            // draws it at 96px, so the largest is a waste of disk and of the download.
            $wanted = $sizes[min(1, count($sizes) - 1)];

            $file = $this->bot->getFile($wanted->file_id);

            if (!$file) {
                return 'failed';
            }

            $name = $telegramId . '.jpg';
            $this->ensureDirectory();
            $target = $this->directory() . '/' . $name;

            if (!$this->bot->downloadFile($file, $target)) {
                return 'failed';
            }

            $user->setPhotoPath($name);
            $this->stamp($user);

            return 'saved';
        } catch (\Throwable $e) {
            $this->logger->warning('avatar sync failed', [
                'telegram_id' => $telegramId,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /** True when something was actually thrown away. */
    private function forget(TelegramUser $user): bool
    {
        $file = $this->fileFor($user);
        $had = $user->getPhotoPath() !== null;

        if ($file !== null) {
            @unlink($file);
        }

        $user->setPhotoPath(null);
        $this->stamp($user);

        return $had;
    }

    private function stamp(TelegramUser $user): void
    {
        $user->setPhotoCheckedAt(new \DateTime());
        $this->em->flush();
    }

    private function ensureDirectory(): void
    {
        $dir = $this->directory();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . $dir);
        }
    }
}
