<?php

namespace App\Entity;

use App\Repository\SmsRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One press of «Так, відправити» in the panel: how many were queued, and whether it was stopped.
 *
 * Only the frame. Who got what stays in `sms_log`, which is the record; what a run has done so
 * far is counted from its rows there, so the two cannot disagree.
 */
#[ORM\Entity(repositoryClass: SmsRunRepository::class)]
#[ORM\Table(name: 'sms_run')]
#[ORM\Index(name: 'sms_run_status_idx', columns: ['status'])]
class SmsRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_STOPPED = 'stopped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Kyiv wall clock, like `sms_log.created_at`: a run belongs to the day it was pressed on. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: false)]
    private string $created_by;

    /** Jobs queued. The «M» of «надіслано N з M». */
    #[ORM\Column(type: Types::INTEGER, nullable: false)]
    private int $total;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $status = self::STATUS_RUNNING;

    /** Why every job still queued writes a row instead of sending. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $stop_reason = null;

    public function __construct(string $createdBy, int $total, \DateTimeImmutable $now)
    {
        $this->created_by = mb_substr($createdBy, 0, 64);
        $this->total = $total;
        $this->created_at = \DateTime::createFromImmutable($now->setTimezone(new \DateTimeZone('Europe/Kyiv')));
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTime { return $this->created_at; }
    public function getCreatedBy(): string { return $this->created_by; }
    public function getTotal(): int { return $this->total; }
    public function getStatus(): string { return $this->status; }
    public function getStopReason(): ?string { return $this->stop_reason; }

    public function isRunning(): bool { return $this->status === self::STATUS_RUNNING; }
    public function isStopped(): bool { return $this->status === self::STATUS_STOPPED; }

    public function stop(string $reason): self
    {
        $this->status = self::STATUS_STOPPED;
        $this->stop_reason = mb_substr($reason, 0, 255);

        return $this;
    }

    public function finish(): self
    {
        if ($this->isRunning()) {
            $this->status = self::STATUS_FINISHED;
        }

        return $this;
    }

    /** Pressed on this Kyiv day? `created_at` holds the Kyiv wall clock, whatever zone it reads back in. */
    public function isOfTheDay(\DateTimeImmutable $now): bool
    {
        return $this->created_at->format('Y-m-d') === $now->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d');
    }
}
