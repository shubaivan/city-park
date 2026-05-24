<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;

class SchedulePavilionService
{

    public function __construct(
        private ScheduledSetRepository $repository
    ) {}

    /**
     * @param string $pavilion
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int|null $hour
     * @param TelegramUser|null $user
     * @return ScheduledSet[]
     */
    public function getExistSet(string $pavilion, int $year, int $month, int $day, ?int $hour = null, ?TelegramUser $user = null): array
    {
        $scheduledSets = $this->repository->getByParams($pavilion, $year, $month, $day, $hour, $user);
        $scheduled = [];
        foreach ($scheduledSets as $scheduledSet) {
            $scheduled[$scheduledSet->getHour()] = $scheduledSet;
        }

        return $scheduled;
    }

    /**
     * @param TelegramUser $user
     * @return array[]
     */
    public function getOwn(TelegramUser $user)
    {
        $scheduledSets = $this->repository->getOwn($user);
        $ownSchedule = [];
        foreach ($scheduledSets as $set) {
            $ownSchedule[$set->getPavilion()][] = $set;
        }

        return $ownSchedule;
    }

    public function getById(int $id): ?ScheduledSet
    {
        return $this->repository->getById($id);
    }

    /**
     * @return array<string, ScheduledSet[]> Past bookings within the last $days, grouped by 'Y-m-d', newest day first.
     */
    public function getHistory(int $days = 30): array
    {
        $now = self::createNewDate();
        $from = (clone $now)->modify("-{$days} days");
        return $this->getHistoryRange($from, $now);
    }

    /**
     * @return array<string, ScheduledSet[]> Bookings within [$from, $until), grouped by 'Y-m-d', oldest day first.
     */
    public function getHistoryRange(\DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $sets = $this->repository->getHistory($from, $until);

        $grouped = [];
        foreach ($sets as $set) {
            $grouped[$set->getScheduledDateTime()->format('Y-m-d')][] = $set;
        }

        return $grouped;
    }

    /**
     * @return int[] Hours (0-23) already booked by this account on the given day, across all pavilions.
     */
    public function getAccountBookedHours(int $year, int $month, int $day, Account $account): array
    {
        return $this->repository->getBookedHoursForOwnerGroup($year, $month, $day, $account);
    }

    /**
     * @return int[] Hours (0-23) already booked by this account on the given pavilion/day.
     */
    public function getAccountBookedHoursAtPavilion(int $pavilion, int $year, int $month, int $day, Account $account): array
    {
        return $this->repository->getBookedHoursForOwnerGroupPavilion($pavilion, $year, $month, $day, $account);
    }

    public static function createNewDate(string $timeZone = 'Europe/Kyiv'): \DateTime
    {
        return (new \DateTime())->setTimezone(new \DateTimeZone($timeZone));
    }
}