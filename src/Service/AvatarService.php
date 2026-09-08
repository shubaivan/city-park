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

    /**
     * Absolute path of a cached avatar, or null when there is none on disk.
     *
     * `$full` asks for the tap-to-enlarge copy and **falls back to the small one**: the
     * big file only exists for people synced since it was added, and an enlarge that 404s
     * is worse than one that opens a 160px picture.
     */
    public function fileFor(TelegramUser $user, bool $full = false): ?string
    {
        $path = $full ? ($user->getPhotoFullPath() ?? $user->getPhotoPath()) : $user->getPhotoPath();

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

            $this->ensureDirectory();

            // Telegram sends the same picture in several sizes, smallest first. Two of
            // them are worth keeping: the middle one is what a 28px circle and a 72px card
            // are drawn from, and the largest is what opens when somebody taps it. Serving
            // the large one everywhere would put ~60 KB per row on a page of 25 circles.
            $small = $sizes[min(1, count($sizes) - 1)];
            $large = $sizes[count($sizes) - 1];

            $name = $telegramId . '.jpg';

            if (!$this->fetch($small->file_id, $name)) {
                return 'failed';
            }

            $user->setPhotoPath($name);

            // The big copy is a nicety, not the feature: if it fails the small one still
            // renders everywhere and the tap opens that instead.
            $fullName = $telegramId . '-full.jpg';
            $user->setPhotoFullPath(
                $large->file_id !== $small->file_id && $this->fetch($large->file_id, $fullName)
                    ? $fullName
                    : null
            );

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

    private function fetch(string $fileId, string $name): bool
    {
        $file = $this->bot->getFile($fileId);

        return $file !== null && $this->bot->downloadFile($file, $this->directory() . '/' . $name);
    }

    /** True when something was actually thrown away. Both copies go — see the class note. */
    private function forget(TelegramUser $user): bool
    {
        $had = $user->getPhotoPath() !== null || $user->getPhotoFullPath() !== null;

        foreach ([$this->fileFor($user), $this->fileFor($user, full: true)] as $file) {
            if ($file !== null) {
                @unlink($file);
            }
        }

        $user->setPhotoPath(null);
        $user->setPhotoFullPath(null);
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
