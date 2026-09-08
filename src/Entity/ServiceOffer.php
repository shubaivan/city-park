<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\ServiceOfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "🛠 Послуги" — a resident offering what they do: плиточник, електрик,
 * манікюр, репетитор, вигул собак.
 *
 * **There is no category list, and that is the design.** The obvious first shape was a
 * fixed set — «сантехніка», «електрика», «ремонт» — and it breaks on the first person who
 * lays tiles: they are neither, so either the list grows a pigeonhole per trade until
 * nobody reads it, or they pick the closest wrong one. A house of 141 flats will not
 * produce enough adverts for a filter to earn its place either way. So the first field is
 * a free-text {@see $title} in the resident's own words, and it is what the index button
 * shows. When there are enough offers that the list stops being scrollable, what to divide
 * them by will be visible in the data — the same rule by which the «Продаж» tab appeared
 * on the rental board only once flats were actually being sold.
 *
 * **The advert is not necessarily about the person posting it.** «Я хочу розмістити
 * телефон свого друга електрика» — so the phone is a plain field, typed in, and the
 * resident's own number is only the one-tap default. That is why the flat on the card is
 * labelled «👤 Розмістив», never left bare under the trade: a bare «буд. 19, кв. 85» under
 * «Електрик» says the electrician lives there, which is false as soon as somebody
 * recommends a friend. Labelled, the flat says who vouches for this — which is the whole
 * reason to trust a card in a house bot rather than a number off a lamppost.
 *
 * **One active offer per person, not per account.** A rental listing belongs to the flat
 * (the flat is what is on offer, and its address and area come from the Account). An offer
 * belongs to whoever posted it: father recommends his electrician and daughter posts her
 * manicurist on the same особовий рахунок, and one-per-account would make them take turns.
 */
#[ORM\Entity(repositoryClass: ServiceOfferRepository::class)]
#[ORM\Table(name: 'service_offer')]
#[ORM\Index(name: 'so_status_expires_idx', columns: ['status', 'expires_at'])]
#[ORM\HasLifecycleCallbacks()]
class ServiceOffer
{
    use CreatedUpdatedAtAwareTrait;

    public const STATUS_ACTIVE = 'active';
    /** Withdrawn by its author ("вже не беру замовлення"). */
    public const STATUS_REMOVED = 'removed';
    /** Lifetime ran out without the author confirming it is still current. */
    public const STATUS_EXPIRED = 'expired';
    /** Taken down by an admin from /admin/services. */
    public const STATUS_BLOCKED = 'blocked';

    /** How long an offer stays visible before it needs re-confirmation. */
    public const LIFETIME_DAYS = 30;

    /** Days before expiry the "ще актуально?" prompt is sent. */
    public const RENEW_PROMPT_BEFORE_DAYS = 3;

    /**
     * The trade, in the resident's own words — «Плиточник», «Електрик», «Манікюр вдома».
     *
     * Short because it is an inline button caption and Telegram truncates those; long
     * enough that nobody has to abbreviate themselves into a category.
     */
    public const TITLE_MAX = 60;

    /**
     * How many live offers one person may have.
     *
     * A person really can be an electrician *and* fit kitchens, and «Електрик, ремонт під
     * ключ» crammed into one 60-character button serves neither trade. Three is the point
     * past which it stops being "I do a few things" and starts being one resident holding
     * the first page — and this board is the only place in the bot where somebody
     * broadcasts to the whole house.
     */
    public const MAX_PER_AUTHOR = 3;

    /** Max photos — enough to show finished work, few enough to keep the card fast. */
    public const PHOTOS_MAX = 3;

    /** How long an upload link stays valid. */
    public const PHOTO_TOKEN_TTL_HOURS = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The household the person belongs to — where «буд. 19, кв. 85» is read from.
     *
     * Not the subject of the advert (that is the person), but the reason a neighbour
     * answers it: this is somebody from the building, not a card in a lift.
     */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    /** Who does the work. Owns the offer: edits it, takes it down, gets the enquiries. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $author = null;

    #[ORM\Column(type: 'string', length: 16, nullable: false, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: false)]
    private string $title = '';

    /**
     * The bot's own post in the residents' chat, so it can be deleted when the offer
     * closes. Same rule as a rental listing: a classifieds thread holding services
     * nobody provides any more is worse than no thread at all.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $chat_message_id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $expires_at;

    /** When the one-shot "ще актуально?" prompt went out. NULL = not sent yet. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $renew_prompt_sent_at = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $closed_at = null;

    /** Admin login that took the offer down, when status = blocked. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $closed_by = null;

    /**
     * Public paths of the work photos, e.g. "/uploads/service-photos/2026/09/x.jpg".
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON, nullable: false, options: ['default' => '[]'])]
    private array $photos = [];

    /**
     * One-shot upload link.
     *
     * Photos do NOT arrive as Telegram pictures, for the same reason they do not on a
     * rental listing or a complaint: `pavilion:photo:check` materialises a
     * PhotoUploadRequest only every 20 minutes, so any in-bot rule of the shape "no open
     * obligation ⇒ this must be a work photo" would swallow the альтанка photo of the
     * resident who sent it immediately — the most conscientious one — and the cron would
     * then block them for evidence they had already sent. A picture sent to the bot is
     * always pavilion evidence, with no rule to get wrong.
     */
    #[ORM\Column(type: 'string', length: 32, nullable: true, unique: true)]
    private ?string $photo_token = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $photo_token_expires_at = null;

    /**
     * The number to ring, display-formatted — whoever's it is.
     *
     * A snapshot and a plain field, not a flag over the registry: the poster may be
     * publishing their own number, and may equally be publishing their electrician's. NULL
     * means no number was given, and the card falls back to reaching the poster through
     * Telegram.
     *
     * When it *is* their own, it still comes from an explicit tap rather than being filled
     * in for them — the number is in our database because they gave it to the ОСББ for
     * нарахування, not for publication.
     */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $contact_phone = null;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = mb_substr(trim($title), 0, self::TITLE_MAX, 'UTF-8');

        return $this;
    }

    public function getChatMessageId(): ?int
    {
        return $this->chat_message_id;
    }

    public function setChatMessageId(?int $id): static
    {
        $this->chat_message_id = $id;

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

    public function setRenewPromptSentAt(?\DateTime $at): static
    {
        $this->renew_prompt_sent_at = $at;

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
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at > new \DateTime();
    }

    /** @return string[] */
    public function getPhotos(): array
    {
        return array_values(array_filter($this->photos ?? []));
    }

    /** @param string[] $photos */
    public function setPhotos(array $photos): static
    {
        $this->photos = array_values(array_slice(array_filter($photos), 0, self::PHOTOS_MAX));

        return $this;
    }

    public function hasPhotos(): bool
    {
        return $this->getPhotos() !== [];
    }

    public function coverPhoto(): ?string
    {
        return $this->getPhotos()[0] ?? null;
    }

    public function getPhotoToken(): ?string
    {
        return $this->photo_token;
    }

    public function setPhotoToken(?string $photo_token): static
    {
        $this->photo_token = $photo_token;

        return $this;
    }

    public function getPhotoTokenExpiresAt(): ?\DateTime
    {
        return $this->photo_token_expires_at;
    }

    public function setPhotoTokenExpiresAt(?\DateTime $at): static
    {
        $this->photo_token_expires_at = $at;

        return $this;
    }

    public function isPhotoTokenValid(\DateTime $now): bool
    {
        return $this->photo_token !== null
            && $this->photo_token_expires_at !== null
            && $this->photo_token_expires_at > $now;
    }

    public function getContactPhone(): ?string
    {
        return $this->contact_phone;
    }

    public function setContactPhone(?string $contact_phone): static
    {
        $this->contact_phone = $contact_phone;

        return $this;
    }

    /** The number to render in the offer, or NULL when none was given. */
    public function publicPhone(): ?string
    {
        return $this->contact_phone;
    }
}
