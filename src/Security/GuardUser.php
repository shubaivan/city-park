<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The gate, signed in through the bot's one-time link. No password exists for it.
 * Carries the Telegram id so the session ends the moment the id leaves GUARD_TELEGRAM_IDS.
 */
final class GuardUser implements UserInterface
{
    /** What the nav bar and the sign-in log show. */
    public const IDENTIFIER = 'ohorona';

    public function __construct(private readonly string $telegramId) {}

    public function getTelegramId(): string
    {
        return $this->telegramId;
    }

    public function getRoles(): array
    {
        return ['ROLE_GUARD'];
    }

    public function getUserIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function eraseCredentials(): void {}
}
