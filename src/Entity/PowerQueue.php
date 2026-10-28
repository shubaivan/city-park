<?php

namespace App\Entity;

use App\Repository\PowerQueueRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which hourly-outage sub-queue a building of the ЖК is in, as Черкасиобленерго last
 * said — one row per building on Козацька.
 *
 * Stored rather than asked on every post: the schedule cron runs every two minutes and
 * the company's cabinet is somebody else's server. Asked daily by `power:queue:check`;
 * `changed_at` is what makes «нас перевели в іншу чергу» something the house can be told
 * once, on the day it happened, rather than discovered when the lights go out off-plan.
 */
#[ORM\Entity(repositoryClass: PowerQueueRepository::class)]
#[ORM\UniqueConstraint(name: 'power_queue_house_uniq', columns: ['house'])]
class PowerQueue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $house;

    /** «3.1»; NULL while the company has not named one for this building. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $queue = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $checked_at;

    /** When `queue` last took a different value — the day the house was moved. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $changed_at = null;

    public function __construct(string $house)
    {
        $this->house = $house;
        $this->checked_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHouse(): string
    {
        return $this->house;
    }

    public function getQueue(): ?string
    {
        return $this->queue;
    }

    /** Records the answer; returns true when it differs from what was stored. */
    public function record(string $queue): bool
    {
        $this->checked_at = new \DateTimeImmutable();

        if ($queue === $this->queue) {
            return false;
        }

        $this->queue = $queue;
        $this->changed_at = $this->checked_at;

        return true;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checked_at;
    }

    public function getChangedAt(): ?\DateTimeImmutable
    {
        return $this->changed_at;
    }
}
