<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\BlockVoteCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A vote of the house. Two kinds, one entity — see {@see KIND_BLOCK} and
 * {@see KIND_QUESTION}.
 *
 * Everything around a vote is identical for both: who may vote, one ballot per account
 * changeable until the deadline, the eligible count snapshotted at open so a vote cannot
 * become un-winnable mid-run, the 7-day deadline, the broadcast, the reminder, the tally
 * cron, the archive. What differs is what the vote is *about* and what happens when it
 * ends. That is the same call the rental board made about rent and sale, for the same
 * reason: two entities would be two copies of the machinery and one of them would rot.
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

    /**
     * A question that reached its deadline. Deliberately not «passed» or «failed».
     *
     * A block campaign has a threshold and crossing it *does* something, so passed/failed
     * is a statement about an action taken. A question does nothing on its own: it records
     * what the house answered, and the ОСББ decides. Declaring «рішення прийнято» off 3
     * ballots out of 180 would be the bot inventing a mandate nobody gave it — so the
     * result is the counts, and the counts are what the archive shows.
     */
    public const STATUS_CLOSED = 'closed';

    /** Somebody is proposed for a 30-day block from booking the альтанка. */
    public const KIND_BLOCK = 'block';

    /**
     * A question put to the house — шлагбаум, тариф, дитячий майданчик.
     *
     * Yes/no, because that is what this bot can ask honestly with one tap per person and
     * one ballot per flat. A question needing three options is a question needing a
     * meeting, and pretending otherwise would produce a number the ОСББ then has to
     * explain away.
     */
    public const KIND_QUESTION = 'question';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, nullable: false, options: ['default' => self::KIND_BLOCK])]
    private string $kind = self::KIND_BLOCK;

    /** The account proposed for blocking. NULL on a question — there is nobody on trial. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'candidate_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Account $candidate = null;

    /** What the house is being asked. NULL on a block — the candidate is the subject. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $question = null;

    /** Optional background: why this is being asked, what the options mean. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = in_array($kind, [self::KIND_BLOCK, self::KIND_QUESTION], true) ? $kind : self::KIND_BLOCK;
        return $this;
    }

    public function isQuestion(): bool
    {
        return $this->kind === self::KIND_QUESTION;
    }

    public function isBlock(): bool
    {
        return $this->kind === self::KIND_BLOCK;
    }

    public function getCandidate(): ?Account
    {
        return $this->candidate;
    }

    public function setCandidate(?Account $candidate): self
    {
        $this->candidate = $candidate;
        return $this;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function setQuestion(?string $question): self
    {
        $question = $question === null ? null : trim($question);
        $this->question = ($question === null || $question === '') ? null : $question;
        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): self
    {
        $details = $details === null ? null : trim($details);
        $this->details = ($details === null || $details === '') ? null : $details;
        return $this;
    }

    /** One line naming what this vote is about, whichever kind it is. */
    public function subject(): string
    {
        return $this->isQuestion()
            ? (string)$this->question
            : ($this->candidate?->getPlaceLabel() ?? 'невідомий об’єкт');
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

    /**
     * Smallest YES count that strictly exceeds PASS_FRACTION of the eligible snapshot.
     *
     * Zero for a question: nothing is triggered by crossing a line, so there is no line.
     * Callers must not print «треба N» for one — «потрібно 55 голосів» beside a question
     * that decides nothing is a promise the bot cannot keep.
     */
    public function yesNeeded(): int
    {
        return $this->isQuestion()
            ? 0
            : (int) floor($this->eligible_count * self::PASS_FRACTION) + 1;
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
