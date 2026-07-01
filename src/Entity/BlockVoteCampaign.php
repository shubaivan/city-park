<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\BlockVoteCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A community vote-to-block campaign: admins (Alina / Luda / main_admin) open one
 * per candidate Account. Every eligible voter (active apartment account, the candidate
 * excluded) may cast a single ballot. When YES > 30% of the eligible count snapshotted
 * at creation, the candidate is auto-blocked for 30 days. Tallied either the instant the
 * threshold is crossed or when the 7-day deadline passes (block-vote:tally cron).
 */
#[ORM\Entity(repositoryClass: BlockVoteCampaignRepository::class)]
#[ORM\Table(name: 'block_vote_campaign')]
#[ORM\Index(name: 'bvc_status_deadline_idx', columns: ['status', 'deadline_at'])]
#[ORM\HasLifecycleCallbacks()]
class BlockVoteCampaign
{
    use CreatedUpdatedAtAwareTrait;

    public const STATUS_OPEN      = 'open';
    public const STATUS_PASSED    = 'passed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The account proposed for blocking. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'candidate_account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Account $candidate;

    #[ORM\Column(type: 'string', length: 16, nullable: false, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    /** Number of eligible voters at the moment the campaign opened — the threshold denominator. */
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $eligible_count = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $deadline_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $closed_at = null;

    /** YES tally snapshotted when the campaign closed (null while open). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $result_yes = null;

    /** NO tally snapshotted when the campaign closed (null while open). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $result_no = null;

    /** Admin login that opened the campaign. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $created_by = null;

    /** When the one-shot final-day reminder (to non-voters) was sent. NULL = not yet sent. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $final_reminder_sent_at = null;

    #[ORM\OneToMany(targetEntity: BlockVoteBallot::class, mappedBy: 'campaign', cascade: ['persist', 'remove'])]
    private Collection $ballots;

    public function __construct()
    {
        $this->ballots = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCandidate(): Account
    {
        return $this->candidate;
    }

    public function setCandidate(Account $candidate): self
    {
        $this->candidate = $candidate;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function getEligibleCount(): int
    {
        return $this->eligible_count;
    }

    public function setEligibleCount(int $eligible_count): self
    {
        $this->eligible_count = $eligible_count;
        return $this;
    }

    /** Fraction of the eligible snapshot YES must exceed for the campaign to pass. */
    public const PASS_FRACTION = 0.30;

    /** Smallest YES count that strictly exceeds PASS_FRACTION of the eligible snapshot. */
    public function yesNeeded(): int
    {
        return (int) floor($this->eligible_count * self::PASS_FRACTION) + 1;
    }

    public function getDeadlineAt(): \DateTime
    {
        return $this->deadline_at;
    }

    public function setDeadlineAt(\DateTime $deadline_at): self
    {
        $this->deadline_at = $deadline_at;
        return $this;
    }

    public function getClosedAt(): ?\DateTime
    {
        return $this->closed_at;
    }

    public function setClosedAt(?\DateTime $closed_at): self
    {
        $this->closed_at = $closed_at;
        return $this;
    }

    public function getResultYes(): ?int
    {
        return $this->result_yes;
    }

    public function setResultYes(?int $result_yes): self
    {
        $this->result_yes = $result_yes;
        return $this;
    }

    public function getResultNo(): ?int
    {
        return $this->result_no;
    }

    public function setResultNo(?int $result_no): self
    {
        $this->result_no = $result_no;
        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->created_by;
    }

    public function setCreatedBy(?string $created_by): self
    {
        $this->created_by = $created_by;
        return $this;
    }

    public function getFinalReminderSentAt(): ?\DateTime
    {
        return $this->final_reminder_sent_at;
    }

    public function setFinalReminderSentAt(?\DateTime $at): self
    {
        $this->final_reminder_sent_at = $at;
        return $this;
    }

    /**
     * @return Collection<int, BlockVoteBallot>
     */
    public function getBallots(): Collection
    {
        return $this->ballots;
    }
}
