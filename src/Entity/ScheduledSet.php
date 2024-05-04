<?php

namespace App\Entity;

use App\Repository\ScheduledSetRepository;
use App\Validator\ScheduleLimit;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: ScheduledSetRepository::class)]
#[ORM\Index(name: "unique_set", columns: ["telegram_user_id", "year", "month", "day", "hour", "pavilion"], options: ['unique' => true])]
#[UniqueEntity(fields: ["telegramUserId", "year", "month", "day", "hour", "pavilion"], message: 'Хтось вже забронював. Оберіть інший час')]
#[ScheduleLimit]
class ScheduledSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[NotBlank]
    #[ORM\ManyToOne(targetEntity: TelegramUser::class, inversedBy: 'scheduledSet')]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id')]
    private TelegramUser $telegramUserId;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $year;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $month;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $day;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $hour;

    #[NotBlank]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $pavilion;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramUserId(): TelegramUser
    {
        return $this->telegramUserId;
    }

    public function setTelegramUserId(TelegramUser $telegramUserId): ScheduledSet
    {
        $this->telegramUserId = $telegramUserId;

        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): ScheduledSet
    {
        $this->year = $year;

        return $this;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): ScheduledSet
    {
        $this->month = $month;

        return $this;
    }

    public function getDay(): int
    {
        return $this->day;
    }

    public function setDay(int $day): ScheduledSet
    {
        $this->day = $day;

        return $this;
    }

    public function getHour(): int
    {
        return $this->hour;
    }

    public function setHour(int $hour): ScheduledSet
    {
        $this->hour = $hour;

        return $this;
    }

    public function getPavilion(): int
    {
        return $this->pavilion;
    }

    public function setPavilion(int $pavilion): ScheduledSet
    {
        $this->pavilion = $pavilion;

        return $this;
    }


}
