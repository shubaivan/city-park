<?php

namespace App\Security;

use App\Service\GuardService;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Keeps a guard's session alive between requests — and ends it once the Telegram id is
 * no longer in GUARD_TELEGRAM_IDS.
 *
 * Loads nobody by name: the login form must never reach a GuardUser (it has no password),
 * so the only way in is the link's authenticator.
 *
 * @implements UserProviderInterface<GuardUser>
 */
class GuardUserProvider implements UserProviderInterface
{
    public function __construct(private GuardService $guard) {}

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof GuardUser) {
            throw new UnsupportedUserException(get_debug_type($user));
        }

        if (!$this->guard->isGuardTelegramId($user->getTelegramId())) {
            throw new UserNotFoundException('No longer a guard.');
        }

        return new GuardUser($user->getTelegramId());
    }

    public function supportsClass(string $class): bool
    {
        return $class === GuardUser::class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $e = new UserNotFoundException('Guards sign in through the bot only.');
        $e->setUserIdentifier($identifier);

        throw $e;
    }
}
