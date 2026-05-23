<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;

final class DebtPolicy
{
    public function __construct(
        private readonly int $threshold,
        private readonly AccountRepository $accountRepository,
    ) {}

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function isOverThreshold(float $debt): bool
    {
        return $debt > $this->threshold;
    }

    /**
     * Per-account debt check — used by the monthly notifier so each over-threshold
     * account gets its own notification.
     */
    public function isAccountBlocked(?Account $account): bool
    {
        if ($account === null || $account->getDebt() === null) {
            return false;
        }

        return (float)$account->getDebt() > $this->threshold;
    }

    /**
     * Group-aware debt check — used by the booking gate so a user with apartment + linked
     * parking can't bypass the block by switching accounts. Returns true if ANY sibling
     * in the owner group is over the threshold (debts are NOT summed — one delinquent
     * unit blocks the entire group).
     */
    public function isOwnerGroupBlocked(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }

        foreach ($this->accountRepository->findGroupSiblings($account) as $sibling) {
            if ((float)($sibling->getDebt() ?? 0) > $this->threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Siblings in the owner group whose debt is over the threshold — used to render the
     * block message so the blocked user sees which specific unit owes money.
     *
     * @return Account[]
     */
    public function getBlockingSiblings(Account $account): array
    {
        $blocking = [];
        foreach ($this->accountRepository->findGroupSiblings($account) as $sibling) {
            if ((float)($sibling->getDebt() ?? 0) > $this->threshold) {
                $blocking[] = $sibling;
            }
        }

        return $blocking;
    }
}
