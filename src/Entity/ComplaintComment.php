<?php

namespace App\Entity;

use App\Repository\ComplaintCommentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the official discussion under a complaint.
 *
 * The register answered "чи взагалі щось відбувається" with a status, and that closed the
 * repeat reports. It did not give the head of the ОСББ any way to *ask* — «яка саме
 * секція?», «воно тече і зараз?», «майстер приїде у вівторок, будете вдома?». Until this
 * existed she had to find the person outside the bot, and whatever they agreed there was
 * known to the two of them only: the register showed «в роботі» and the house drew its own
 * conclusions.
 *
 * Two rules, both deliberate:
 *
 * - **Read by the whole house, written by two people.** Every resident who opens the card
 *   reads the thread — that is what makes it a record rather than a private chat. Only the
 *   author of the complaint and the head of the ОСББ may add to it
 *   (`ComplaintService::mayComment()`). Letting all 141 flats post turns the thread under
 *   the broken lift into the chat this register was built to replace, and then the one
 *   answer that matters — hers — is buried in it.
 * - **Nothing is edited or deleted.** A discussion the parties can rewrite afterwards is
 *   not evidence of anything. The complaint itself stays the author's to retype; what was
 *   already said about it does not. Comments go only when the complaint does, with
 *   `complaint:cleanup`.
 *
 * `author_label` is a snapshot, not a lookup: the row must still read «буд. 19, кв. 85»
 * after the person moves out and `author` is nulled by the FK, and «Людмила (голова ОСББ)»
 * after she is no longer in COMPLAINT_MANAGER_TELEGRAM_IDS. Same reasoning as
 * `RentalListing.contact_phone` — a display value agreed at the time it was written.
 */
#[ORM\Entity(repositoryClass: ComplaintCommentRepository::class)]
#[ORM\Index(columns: ['complaint_id', 'created_at'], name: 'idx_complaint_comment_thread')]
class ComplaintComment
{
    public const TEXT_MAX = 700;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Complaint $complaint = null;

    /**
     * Null for a comment left from /admin/complaints, where the writer is an admin login
     * rather than a Telegram account — and null again once a former resident's row goes.
     * `author_label` carries the display name in both cases.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $author = null;

    #[ORM\Column(length: 120)]
    private string $author_label = '';

    /** Written by the ОСББ, not by the resident — rendered as 🏢 and set apart. */
    #[ORM\Column]
    private bool $official = false;

    #[ORM\Column(type: 'text')]
    private string $text = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $created_at;

    public function __construct()
    {
        $this->created_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getComplaint(): ?Complaint
    {
        return $this->complaint;
    }

    public function setComplaint(?Complaint $complaint): static
    {
        $this->complaint = $complaint;

        return $this;
    }

    public function getAuthor(): ?TelegramUser
    {
        return $this->author;
    }

    public function setAuthor(?TelegramUser $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getAuthorLabel(): string
    {
        return $this->author_label;
    }

    public function setAuthorLabel(string $label): static
    {
        $this->author_label = mb_substr($label, 0, 120);

        return $this;
    }

    public function isOfficial(): bool
    {
        return $this->official;
    }

    public function setOfficial(bool $official): static
    {
        $this->official = $official;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->created_at;
    }
}
