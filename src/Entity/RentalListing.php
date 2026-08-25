<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\RentalListingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Здається квартира" — an owner-published notice that their apartment is up for rent,
 * visible to every resident in the bot's 🔑 Оренда section.
 *
 * Exists to move the recurring "хто здає?" chatter out of the house chat into one list
 * everybody can read. It is deliberately NOT a tenancy record: publishing a listing gives
 * nobody booking rights, a vote, or any responsibility for the photo obligation — the
 * Account stays the single tenant unit exactly as before.
 *
 * One active listing per account (an account is one apartment). Listings self-expire after
 * LIFETIME_DAYS so the list can't rot into half-year-old flats; the owner is asked to
 * confirm shortly before that (rental:expire cron).
 */
#[ORM\Entity(repositoryClass: RentalListingRepository::class)]
#[ORM\Table(name: 'rental_listing')]
#[ORM\Index(name: 'rl_status_expires_idx', columns: ['status', 'expires_at'])]
#[ORM\HasLifecycleCallbacks()]
class RentalListing
{
    use CreatedUpdatedAtAwareTrait;

    public const STATUS_ACTIVE   = 'active';
    /** Withdrawn by the owner ("вже здав" / передумав). */
    public const STATUS_REMOVED  = 'removed';
    /** Lifetime ran out without the owner confirming it is still current. */
    public const STATUS_EXPIRED  = 'expired';
    /** Taken down by an admin from /admin/rentals. */
    public const STATUS_BLOCKED  = 'blocked';

    /** How long a listing stays visible before it needs re-confirmation. */
    public const LIFETIME_DAYS = 30;

    /** Days before expiry the "ще актуально?" prompt is sent. */
    public const RENEW_PROMPT_BEFORE_DAYS = 3;

    /** Free-text description cap, enforced on input so one listing can't flood the list. */
    public const DESCRIPTION_MAX = 400;

    /** Price sanity bounds, UAH per month. Guards against typos like 12 or 1200000. */
    public const PRICE_MIN = 500;
    public const PRICE_MAX = 200000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The apartment being offered. Address/area/apartment number are read from here. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    /** Which family member published it — also who gets the "ще актуально?" prompt and contact relays. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $author = null;

    #[ORM\Column(type: 'string', length: 16, nullable: false, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    /** Rooms, 1..4 (4 means "4 і більше"). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $rooms = null;

    /** UAH per month; NULL means "договірна". */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $price = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $expires_at;

    /** When the one-shot "ще актуально?" prompt went out. NULL = not sent yet. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $renew_prompt_sent_at = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $closed_at = null;

    /** Admin login that took the listing down, when status = blocked. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $closed_by = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): static
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRooms(): ?int
    {
        return $this->rooms;
    }

    public function setRooms(?int $rooms): static
    {
        $this->rooms = $rooms;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getExpiresAt(): \DateTime
    {
        return $this->expires_at;
    }

    public function setExpiresAt(\DateTime $expires_at): static
    {
        $this->expires_at = $expires_at;

        return $this;
    }

    public function getRenewPromptSentAt(): ?\DateTime
    {
        return $this->renew_prompt_sent_at;
    }

    public function setRenewPromptSentAt(?\DateTime $renew_prompt_sent_at): static
    {
        $this->renew_prompt_sent_at = $renew_prompt_sent_at;

        return $this;
    }

    public function getClosedAt(): ?\DateTime
    {
        return $this->closed_at;
    }

    public function setClosedAt(?\DateTime $closed_at): static
    {
        $this->closed_at = $closed_at;

        return $this;
    }

    public function getClosedBy(): ?string
    {
        return $this->closed_by;
    }

    public function setClosedBy(?string $closed_by): static
    {
        $this->closed_by = $closed_by;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Rooms rendered for the listing line; "4" is an open-ended bucket. */
    public function roomsLabel(): ?string
    {
        if ($this->rooms === null) {
            return null;
        }

        return $this->rooms >= 4 ? '4+ кімн.' : $this->rooms . '-кімн.';
    }

    public function priceLabel(): string
    {
        if ($this->price === null) {
            return 'ціна договірна';
        }

        return number_format($this->price, 0, ',', ' ') . ' грн/міс';
    }
}
