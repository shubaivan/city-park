<?php

namespace App\Entity;

use App\Repository\ComplaintRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A house problem somebody reported: a dead lift, a burst hose, a parking gate that
 * will not open.
 *
 * All of this already happens — in the residents' chat, where a report is a message that
 * scrolls away. Three people report the same lift, nobody knows whether the head of the
 * ОСББ saw it, and nobody ever learns when it was fixed. The register exists for the
 * status, not for the list: a resident who opens the bot and reads «🔧 Ліфт не працює —
 * в роботі» does not post the fourth message about it.
 *
 * Deliberately open to everyone the house recognises:
 *
 * - **`is_active` is not checked.** A debt blocks *booking*. Someone who owes money is
 *   still entitled to report that the lift is broken — and they pay for that lift.
 * - **Unit type is not checked either.** "Ворота в паркінг не відчиняються" is by
 *   definition a report from a parking owner, and a storage owner walks through the same
 *   yard; `canBookPavilion()` decides who books the pavilion, not who may report a fault.
 *
 * `complaint:cleanup` keeps the register a list of live problems: a finished complaint is
 * purged a month after it was closed, an untouched one after half a year. Photos go with
 * the row — see the constants below.
 */
#[ORM\Entity(repositoryClass: ComplaintRepository::class)]
#[ORM\Index(columns: ['status'], name: 'idx_complaint_status')]
class Complaint
{
    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';

    /**
     * Known, agreed, and waiting on something the ОСББ does not control — a part, a
     * contractor, a court, the money for it.
     *
     * This is the most common real state of a house problem and the register had no word
     * for it. Without it «в роботі» has to mean both "майстер їде зараз" and "чекаємо
     * насос із Польщі три тижні", and a resident reading it a second week running
     * concludes that nothing is happening — which is exactly the conclusion the whole
     * register exists to prevent.
     *
     * A hold **must** carry a reason: `ComplaintService::changeStatus()` refuses it
     * otherwise. «Відкладено» with no explanation is worse than «в роботі» — it reads as
     * the ОСББ giving up in public.
     */
    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_DONE = 'done';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ON_HOLD,
        self::STATUS_DONE,
    ];

    /** Same ceiling and reasoning as a rental listing: three pictures say everything. */
    public const PHOTOS_MAX = 3;

    public const PHOTO_TOKEN_TTL_HOURS = 24;

    public const TEXT_MAX = 700;

    /**
     * A finished complaint is kept a month after it was closed, then purged with its
     * photos. Long enough to answer "коли це полагодили?", short enough that the register
     * stays a list of live problems rather than an archive.
     */
    public const DONE_RETENTION_DAYS = 30;

    /**
     * An untouched complaint is purged after half a year.
     *
     * I argued for auto-closing these instead, on the grounds that a six-month-old open
     * entry is a record of nobody having done anything. Иван's call, 02.09.2026, was that
     * a problem nobody resolved in half a year was not a real one and will be filed again
     * if it still matters — which is also what keeps the register a list of live problems
     * instead of a monument. Deliberate; don't "restore" the history.
     */
    public const STALE_OPEN_DAYS = 180;

    /** How much of the text becomes the button label in the list. */
    public const LABEL_MAX = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Account $account = null;

    /**
     * Who typed it. Kept beside the account because a flat has several family members and
     * "хто саме написав" is the first thing asked when a report needs clarifying.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $author = null;

    #[ORM\Column(type: 'text')]
    private string $text = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_NEW;

    /** @var string[] public paths under public/uploads/complaint-photos */
    #[ORM\Column(type: 'json')]
    private array $photos = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $photo_token = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $photo_token_expires_at = null;

    /**
     * The «📷 Фото до заявки» message the bot sent to hand over the upload link.
     *
     * Kept so that message can be rewritten into a confirmation the moment a photo lands.
     * The Web App gives the server no "the user closed me" event — people dismiss it with
     * the ✕ as often as with the Готово button — so a confirmation that depends on Готово
     * being pressed simply does not arrive, and the resident returns to the chat facing
     * the same "відкрийте сторінку" message as if nothing had happened.
     */
    #[ORM\Column(nullable: true)]
    private ?int $photo_prompt_message_id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $created_at;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $status_changed_at;

    /** Telegram name or admin login of whoever last moved it — shown on the card. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $status_changed_by = null;

    /**
     * The note attached to the current status: "що зробили" when closing, and the
     * mandatory "чому чекаємо" of a hold.
     *
     * One field rather than two on purpose — it always describes where the complaint
     * stands *now*, and it is already rendered on the card, in the author's notification
     * and in the chat post. A closing note overwriting a hold reason is correct: the
     * history of what was said lives in the comment thread, which nothing overwrites.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resolution = null;

    public function __construct()
    {
        $this->created_at = new \DateTimeImmutable();
        $this->status_changed_at = $this->created_at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

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

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status, ?string $by = null): static
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown complaint status: ' . $status);
        }

        $this->status = $status;
        $this->status_changed_at = new \DateTimeImmutable();
        $this->status_changed_by = $by;

        return $this;
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function isOnHold(): bool
    {
        return $this->status === self::STATUS_ON_HOLD;
    }

    /** A held complaint is still an unsolved problem, so it counts as open everywhere. */
    public function isOpen(): bool
    {
        return !$this->isDone();
    }

    /** @return string[] */
    public function getPhotos(): array
    {
        return $this->photos;
    }

    /** @param string[] $photos */
    public function setPhotos(array $photos): static
    {
        $this->photos = array_values($photos);

        return $this;
    }

    public function getPhotoToken(): ?string
    {
        return $this->photo_token;
    }

    public function setPhotoToken(?string $token, ?\DateTimeImmutable $expiresAt = null): static
    {
        $this->photo_token = $token;
        $this->photo_token_expires_at = $expiresAt;

        return $this;
    }

    public function getPhotoTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->photo_token_expires_at;
    }

    public function getPhotoPromptMessageId(): ?int
    {
        return $this->photo_prompt_message_id;
    }

    public function setPhotoPromptMessageId(?int $messageId): static
    {
        $this->photo_prompt_message_id = $messageId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->created_at;
    }

    public function getStatusChangedAt(): \DateTimeImmutable
    {
        return $this->status_changed_at;
    }

    public function getStatusChangedBy(): ?string
    {
        return $this->status_changed_by;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function setResolution(?string $resolution): static
    {
        $this->resolution = $resolution;

        return $this;
    }
}
