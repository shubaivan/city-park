<?php

namespace App\Service;

use App\Entity\ServiceStaff;
use App\Entity\TelegramUser;
use App\Repository\ServiceStaffRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who of the people talking to the bot is the cleaning crew (Иван, 16.11.2026).
 *
 * Recognition is by phone (PhoneKey, last nine digits) and is stamped with the Telegram id
 * on first match, so later updates are recognised without a phone. **A person linked to a
 * flat is never treated as staff** — they keep their resident menu and their photos stay
 * pavilion evidence; staff is the fallback for somebody the registry does not know.
 */
class CleaningStaffService
{
    public function __construct(
        private ServiceStaffRepository $staff,
        private EntityManagerInterface $em,
        private ?TelegramUserService $users = null,
    ) {}

    /**
     * The active staff row for this user, linking the Telegram id on the first phone match.
     * Never throws: this is read on every menu render.
     */
    public function staffFor(?TelegramUser $user): ?ServiceStaff
    {
        if (!$user instanceof TelegramUser || $user->getAccount() !== null) {
            return null;
        }

        try {
            // A flat linked lazily (conditional owner / expected resident) wins too.
            if ($this->users !== null && $this->users->resolveAccount($user) !== null) {
                return null;
            }

            $telegramId = (string)$user->getTelegramId();
            $byId = $this->staff->findActiveByTelegramId($telegramId);

            if ($byId instanceof ServiceStaff) {
                return $byId;
            }

            $byPhone = $this->staff->findByPhone($user->getPhoneNumber());

            if (!$byPhone instanceof ServiceStaff || !$byPhone->isActive() || $telegramId === '') {
                return null;
            }

            if ($byPhone->getTelegramId() !== $telegramId) {
                $byPhone->link($telegramId);
                $this->em->flush();
            }

            return $byPhone;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isCleaning(?TelegramUser $user): bool
    {
        return $this->staffFor($user)?->getRole() === ServiceStaff::ROLE_CLEANING;
    }

    /** Add (or re-activate) a number. Returns the row and whether it was new. */
    public function add(string $phone, string $label = 'Клінінг', ?string $createdBy = null): ServiceStaff
    {
        if (PhoneKey::of($phone) === '') {
            throw new \InvalidArgumentException('Номер занадто короткий: ' . $phone);
        }

        $row = $this->staff->findByPhone($phone);

        if ($row instanceof ServiceStaff) {
            $row->setActive(true)->setLabel($label);
        } else {
            $row = new ServiceStaff($phone, $label, ServiceStaff::ROLE_CLEANING, $createdBy);
            $this->em->persist($row);
        }

        $this->em->flush();

        return $row;
    }

    /** Switch a number off. The row stays, so the history of who posted what stays readable. */
    public function deactivate(string $phone): ?ServiceStaff
    {
        $row = $this->staff->findByPhone($phone);

        if ($row instanceof ServiceStaff) {
            $row->setActive(false);
            $this->em->flush();
        }

        return $row;
    }
}
