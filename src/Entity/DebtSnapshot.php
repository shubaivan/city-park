<?php

namespace App\Entity;

use App\Repository\DebtSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per debt import: what the house owed, and how many flats owed it.
 *
 * The debt figures themselves are overwritten in place on every import, so without
 * this the house has no memory of its own arrears — «66 490 грн» reads the same whether
 * it fell by four thousand this month or rose by ten. The announcement in the residents'
 * chat is worth posting precisely because it can say which.
 *
 * It also serves as the announcement's own log: the accountant re-uploading a corrected
 * file twenty minutes later must not put a second list of debtors in front of the whole
 * house, so DebtAnnouncer checks whether today's post already went out.
 */
#[ORM\Entity(repositoryClass: DebtSnapshotRepository::class)]
class DebtSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $taken_at;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $total = '0';

    #[ORM\Column]
    private int $debtors = 0;

    /** When the residents' chat was told about this import — NULL if it never was. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $announced_at = null;

    public function __construct()
    {
        $this->taken_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTakenAt(): \DateTimeImmutable
    {
        return $this->taken_at;
    }

    public function getTotal(): float
    {
        return (float)$this->total;
    }

    public function setTotal(float $total): static
    {
        $this->total = (string)$total;

        return $this;
    }

    public function getDebtors(): int
    {
        return $this->debtors;
    }

    public function setDebtors(int $debtors): static
    {
        $this->debtors = $debtors;

        return $this;
    }

    public function getAnnouncedAt(): ?\DateTimeImmutable
    {
        return $this->announced_at;
    }

    public function markAnnounced(): static
    {
        $this->announced_at = new \DateTimeImmutable();

        return $this;
    }
}
