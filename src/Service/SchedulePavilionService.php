<?php

namespace App\Service;

use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;

class SchedulePavilionService
{

    public function __construct(
        private ScheduledSetRepository $repository
    ) {}

    /**
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int|null $hour
     * @param TelegramUser|null $user
     * @return ScheduledSet[]
     */
    public function getExistSet(int $year, int $month, int $day, ?int $hour = null, ?TelegramUser $user = null): array
    {
        $scheduledSets = $this->repository->getByParams($year, $month, $day, $hour, $user);
        $scheduled = [];
        foreach ($scheduledSets as $scheduledSet) {
            $scheduled[$scheduledSet->getHour()] = $scheduledSet;
        }

        return $scheduled;
    }

    /**
     * @param TelegramUser $user
     * @return ScheduledSet[]
     */
    public function getOwn(TelegramUser $user)
    {
        return $this->repository->getOwn($user);
    }

    public function getById(int $id): ?ScheduledSet
    {
        return $this->repository->getById($id);
    }

    public static function createNewDate(string $timeZone = 'Europe/Kyiv'): \DateTime
    {
        return (new \DateTime())->setTimezone(new \DateTimeZone($timeZone));
    }
}