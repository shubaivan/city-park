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
 * **One active offer per person, not per account.** A rental listing belongs to the flat
 * (the flat is what is on offer, and its address and area come from the Account). A
 * service belongs to whoever performs it: father is an electrician and daughter does
 * manicures on the same особовий рахунок, and one-per-account would make them take turns.
 * The Account is still required — it is where «буд. 19, кв. 85» comes from, and that line
 * is most of why a neighbour trusts the advert.
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

    public const DESCRIPTION_MAX = 400;

    /**
     * Free text, not a number.
     *
     * A flat has one rent; a trade has «від 500 грн», «300 грн/год», «за домовленістю»,
     * «безкоштовно сусідам». Forcing that into an integer would make every honest answer
     * a lie, so the field takes what the person actually says and caps it at a length
     * that still fits under the title.
     */
    public const PRICE_MAX = 48;

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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** NULL means "договірна" — the same thing, said by not saying it. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $price_note = null;

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
     * Whether the author agreed to publish their phone in the offer.
     *
     * Opt-in, never inferred — the number is in our database because the resident gave it
     * to the ОСББ for нарахування. It matters more here than on a rental board: a plumber
     * usually *wants* to be phoned, but that is still their decision and not ours.
     */
    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => false])]
    private bool $show_phone = false;

    /**
     * Display-formatted snapshot of the number taken at publish time — consent was given
     * for THIS number, so a later registry change does not silently republish another.
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPriceNote(): ?string
    {
        return $this->price_note;
    }

    public function setPriceNote(?string $price_note): static
    {
        $price_note = $price_note === null ? null : trim($price_note);

        $this->price_note = ($price_note === null || $price_note === '')
            ? null
            : mb_substr($price_note, 0, self::PRICE_MAX, 'UTF-8');

        return $this;
    }

    /** What goes on the card and the button. NULL is spelled out, never left blank. */
    public function priceLabel(): string
    {
        return $this->price_note ?? 'ціна договірна';
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

    public function isShowPhone(): bool
    {
        return $this->show_phone;
    }

    public function setShowPhone(bool $show_phone): static
    {
        $this->show_phone = $show_phone;

        return $this;
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

    /** The number to render in the offer, or NULL when the author kept it private. */
    public function publicPhone(): ?string
    {
        return $this->show_phone ? $this->contact_phone : null;
    }
}
