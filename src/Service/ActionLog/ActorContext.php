<?php

namespace App\Service\ActionLog;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who is acting right now, for the «Журнал дій».
 *
 * In order: the signed-in panel user; else the bot, when the request is a Telegram update
 * (`bot:<telegram id>` of whoever pressed the button); else the console command that is
 * running (`console:debt:import-file`); else `system`.
 *
 * A request id ties the request row to the field changes it caused. Reset between
 * requests (ResetInterface) so the long-running Messenger worker does not carry one
 * message's actor into the next.
 */
class ActorContext implements ResetInterface
{
    private ?string $botTelegramId = null;
    private bool $isBot = false;
    private ?string $command = null;
    private ?string $requestId = null;

    public function __construct(
        private TokenStorageInterface $tokens,
        private RequestStack $requests,
    ) {}

    public function markBot(?string $telegramId): void
    {
        $this->isBot = true;
        $this->botTelegramId = $telegramId !== null && $telegramId !== '' ? $telegramId : null;
    }

    public function markCommand(string $name): void
    {
        $this->command = $name;
    }

    public function isSignedIn(): bool
    {
        return $this->user() !== null;
    }

    public function actor(): string
    {
        $user = $this->user();

        if ($user !== null) {
            return mb_substr($user->getUserIdentifier(), 0, 80);
        }

        if ($this->isBot) {
            return $this->botTelegramId !== null ? 'bot:' . $this->botTelegramId : 'bot';
        }

        if ($this->command !== null) {
            return mb_substr('console:' . $this->command, 0, 80);
        }

        return 'system';
    }

    private function user(): ?\Symfony\Component\Security\Core\User\UserInterface
    {
        try {
            return $this->tokens->getToken()?->getUser();
        } catch (\Throwable) {
            return null;
        }
    }

    public function requestId(): string
    {
        return $this->requestId ??= bin2hex(random_bytes(6));
    }

    public function ip(): ?string
    {
        return $this->requests->getMainRequest()?->getClientIp();
    }

    public function userAgent(): ?string
    {
        $agent = (string)$this->requests->getMainRequest()?->headers->get('User-Agent');

        return $agent !== '' ? mb_substr($agent, 0, 120) : null;
    }

    public function reset(): void
    {
        $this->botTelegramId = null;
        $this->isBot = false;
        $this->requestId = null;
        // The command is kept: a worker stays the same command across its messages.
    }
}
