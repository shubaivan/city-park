<?php

namespace App\Service;

use App\Entity\TelegramUser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The gate's way into the panel: a one-time link the bot hands to a guard (Иван,
 * 12.11.2026 — no password at the post, the post phone's Telegram is the key).
 *
 * - Only for ids in `GUARD_TELEGRAM_IDS`, checked when the link is made **and** when it is
 *   opened: taking an id off the list stops a link already sent.
 * - Lives {@see TTL_MINUTES} minutes and works once. Only its SHA-256 is stored — the
 *   token itself exists in the Telegram message alone, so the table is useless to whoever
 *   reads it.
 *
 * Same shape as the CRM's login link in the sister project.
 */
class PanelLoginLink
{
    public const TTL_MINUTES = 5;

    public function __construct(
        private PanelLoginTokenStore $store,
        private GuardService $guard,
        private UrlGeneratorInterface $urls,
    ) {}

    /** The absolute link, or null when this person is not the gate. */
    public function issue(?TelegramUser $user): ?string
    {
        if (!$this->guard->isGuard($user)) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Kyiv'));

        $this->store->save(self::hash($token), (string)$user->getTelegramId(), $now->modify('+' . self::TTL_MINUTES . ' minutes'));

        return $this->urls->generate('app_panel_auth', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Whose link it was, burning it; null for anything that must not get in. */
    public function consume(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $telegramId = $this->store->consume(self::hash($token), new \DateTimeImmutable('now', new \DateTimeZone('Europe/Kyiv')));

        if ($telegramId === null || !$this->guard->isGuardTelegramId($telegramId)) {
            return null;
        }

        return $telegramId;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
