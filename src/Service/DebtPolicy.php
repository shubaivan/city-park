<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\TariffRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DebtPolicy
{
    /**
     * Debt > monthly_fee × OVER_FACTOR triggers a block. monthly_fee = area × price_per_meter.
     * Equivalent to "1.5 monthly fees" — anything more than a month-and-a-half of arrears.
     */
    public const OVER_FACTOR = 1.5;

    public function __construct(
        private readonly int $threshold,
        private readonly AccountRepository $accountRepository,
        private readonly TariffRepository $tariffRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Legacy global fallback — used when an account has no area set or the tariff
     * price isn't configured yet.
     */
    public function getThreshold(): int
    {
        return $this->threshold;
    }

    /**
     * Per-account threshold. Returns area × price × 1.5 when both inputs are
     * available; otherwise falls back to the legacy global threshold so we
     * don't accidentally over- or under-block during rollout.
     */
    public function getThresholdFor(?Account $account): float
    {
        if ($account === null) {
            return (float)$this->threshold;
        }

        $area = $account->getArea();
        if ($area === null || (float)$area <= 0) {
            return (float)$this->threshold;
        }

        $price = (float)$this->tariffRepository->getOrCreate($this->em)->getPricePerMeter();
        if ($price <= 0) {
            return (float)$this->threshold;
        }

        return round((float)$area * $price * self::OVER_FACTOR, 2);
    }

    /**
     * Whether `$debt` exceeds the threshold computed for `$account`. When called
     * without an account, falls back to the global constant — needed by callers
     * that don't have an Account in hand.
     */
    public function isOverThreshold(float $debt, ?Account $account = null): bool
    {
        return $debt > $this->getThresholdFor($account);
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

        return (float)$account->getDebt() > $this->getThresholdFor($account);
    }

    /**
     * Group-aware debt check — used by the booking gate so a user with apartment + linked
     * parking can't bypass the block by switching accounts. Returns true if ANY sibling
     * in the owner group is over its own threshold (debts are NOT summed — one delinquent
     * unit blocks the entire group).
     */
    public function isOwnerGroupBlocked(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }

        foreach ($this->accountRepository->findGroupSiblings($account) as $sibling) {
            if ((float)($sibling->getDebt() ?? 0) > $this->getThresholdFor($sibling)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Siblings in the owner group whose debt is over their threshold — used to render the
     * block message so the blocked user sees which specific unit owes money.
     *
     * @return Account[]
     */
    public function getBlockingSiblings(Account $account): array
    {
        $blocking = [];
        foreach ($this->accountRepository->findGroupSiblings($account) as $sibling) {
            if ((float)($sibling->getDebt() ?? 0) > $this->getThresholdFor($sibling)) {
                $blocking[] = $sibling;
            }
        }

        return $blocking;
    }
}
