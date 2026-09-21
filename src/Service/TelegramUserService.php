<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\ExpectedResidentRepository;
use App\Repository\TelegramUserRepository;
use Doctrine\ORM\EntityManagerInterface;

class TelegramUserService
{
    private ?TelegramUser $currentUser;

    public function __construct(
        private TelegramUserRepository $telegramUserRepository,
        private ExpectedResidentRepository $expectedResidents,
        private EntityManagerInterface $em
    ) {}

    public function initUser(array $from)
    {
        $telegramUser = new TelegramUser();
        $this->currentUser = $this->telegramUserRepository->getByTelegramId($from['id']);
        if (!$this->currentUser) {
            $telegramUser->setTelegramId($from['id']);

            if (isset($from['first_name'])) {
                $telegramUser->setFirstName($from['first_name']);
            }

            if (isset($from['last_name'])) {
                $telegramUser->setLastName($from['last_name']);
            }

            if (isset($from['username'])) {
                $telegramUser->setUsername($from['username']);
            }

            if (isset($from['language_code'])) {
                $telegramUser->setLanguageCode($from['language_code']);
            }

            if (isset($from['chat_id'])) {
                $telegramUser->setChatId($from['chat_id']);
            }

            $this->telegramUserRepository->save($telegramUser);

            $this->currentUser = $telegramUser;
        }

        if (isset($from['chat_id'])) {
            $this->currentUser->setChatId($from['chat_id']);
            $this->em->flush();
        }

        return $this->currentUser;
    }

    public function savePhone(string $phone_number): void
    {
        $this->currentUser->setPhoneNumber($phone_number);
    }

    public function getCurrentUser(): ?TelegramUser
    {
        return $this->currentUser;
    }

    /**
     * Return the Account a user may book against.
     *
     * If the user has no account of their own, try to resolve one via their
     * confirmed phone being listed as a "умовний власник" (conditional owner)
     * on an account holder's record. When found, the link is persisted so the
     * family member becomes a normal member of that account.
     */
    public function resolveAccount(TelegramUser $user): ?Account
    {
        if ($user->getAccount()) {
            return $user->getAccount();
        }

        $account = $this->telegramUserRepository->findAccountByConditionalPhone(
            $user->getPhoneNumber()
        );

        if ($account) {
            $user->setAccount($account);
            $this->em->flush();

            return $account;
        }

        return $this->claimExpected($user);
    }

    /**
     * The second door: a number the ОСББ wrote down against an object before its owner
     * ever opened the bot.
     *
     * The conditional-phone lookup above hangs off a TelegramUser, so it can only ever
     * work on an object that already has somebody in the bot — and roughly 790 of the
     * ЖК's 966 objects have nobody, which is exactly the set whose debts and blocks
     * reach no one. `ExpectedResident` is where the accountant records what she was told
     * («буд. 19, кв. 50 — Іван Доненко, +380…»), and this is where it takes effect.
     *
     * It runs from every resolveAccount() call, not only from /phone, so somebody who
     * shared their number weeks ago and was told «в реєстрі ОСББ його немає» is linked
     * the next time they open anything, with nobody having to ask them to press /phone
     * again.
     *
     * The registry name is copied onto the resident when they have none of their own:
     * the bot holds no owner names, so this is usually the only real name that exists
     * for them, and it is what makes them findable in /admin/users by the name the
     * accountant knows.
     */
    private function claimExpected(TelegramUser $user): ?Account
    {
        $expected = $this->expectedResidents->findUnclaimedByPhone($user->getPhoneNumber());

        if ($expected === null || !$expected->getAccount() instanceof Account) {
            return null;
        }

        $account = $expected->getAccount();

        $user->setAccount($account);
        if (!$user->getFullName() && $expected->getFullName()) {
            $user->setFullName($expected->getFullName());
        }
        $expected->claim($user);
        $this->em->flush();

        return $account;
    }
}