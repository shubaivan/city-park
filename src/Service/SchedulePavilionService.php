<?php

namespace App\Service;

use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;

class SchedulePavilionService
{

    public function __construct(
        private ScheduledSetRepository $repository
    ) {}

    public function getExistSet(int $year, int $month, int $day, ?int $hour = null, ?TelegramUser $user = null)
    {
        $scheduledSets = $this->repository->getByParams($year, $month, $day, $hour, $user);
        $scheduled = [];
        foreach ($scheduledSets as $scheduledSet) {
            $scheduled[$scheduledSet->getHour()] = $scheduledSet->getTelegramUserId()->concatNameInfo();
        }

        return $scheduled;
    }
}