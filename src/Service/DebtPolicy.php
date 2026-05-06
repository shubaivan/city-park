<?php

namespace App\Service;

use App\Entity\Account;

final class DebtPolicy
{
    public function __construct(private readonly int $threshold) {}

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function isOverThreshold(float $debt): bool
    {
        return $debt > $this->threshold;
    }

    public function isAccountBlocked(?Account $account): bool
    {
        if ($account === null || $account->getDebt() === null) {
            return false;
        }

        return (float)$account->getDebt() > $this->threshold;
    }
}
