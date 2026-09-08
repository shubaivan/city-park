<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\TelegramUserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: TelegramUserRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class TelegramUser
{
    use CreatedUpdatedAtAwareTrait;

    public static array $dataTableFields = [
        'id',
        'account_number',
        'apartment_number',
        'house_number',
        'street',
        'is_active',
        'debt',
        'area',
        'debt_threshold',
        'phone_number',
        'additional_phones',
        'first_name',
        'last_name',
        'username',
        'start',
        'last_visit'
    ];


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $chatId;
    #[ORM\Column(type: 'string', length: 255, unique: true, nullable: false)]
    private ?string $telegram_id;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $phone_number;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $first_name;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $last_name;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $username;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $language_code;

    #[ORM\Column(type: 'json', nullable: true, options: ['default' => '{}'])]
    private ?array $additional_phones = [];

    #[ORM\OneToMany(targetEntity: ScheduledSet::class, mappedBy: 'telegramUserId', cascade: ["persist"])]
    private Collection $scheduledSet;

    /**
     * How this person relates to the flat: owner, family member, or tenant.
     *
     * The bot cannot work this out and never could — it holds no owner names, only a flat
     * and a phone. It is set by the accountant, who is told it in plain words («у мене
     * орендатори», «я орендар») and until now had nowhere to write it down.
     *
     * NULL means nobody has said, and that is deliberately distinct from "власник":
     * guessing would put a confident wrong label on most rows and make the field useless
     * for the one thing it is for — knowing who actually lives behind each door.
     */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $role = null;

    /**
     * ПІБ as the ОСББ's own registry spells it — «Шуба Іван Вікторович».
     *
     * Deliberately not `first_name`/`last_name`: those are what Telegram reports, they are
     * whatever the person chose to call themselves («63691», «Я Знову Я», «Daniil 🌍🌍🌍»),
     * and they are how the accountant recognises who is writing in the chat. The registry
     * name is a different fact about the same person, and the ОСББ needs both — one to
     * match a квитанція, the other to match a message.
     *
     * The bot never writes here: `initUser()` fills the Telegram fields once, on creation,
     * and touches nothing but `chat_id` afterwards. This is filled by hand until the
     * accountant's registry file arrives, and that file will land in this column.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $full_name = null;

    /**
     * The Telegram profile photo, cached under `var/avatars/` — never under `public/`.
     *
     * It is a resident's face; it is served through `/admin/avatar/{id}` behind the panel's
     * own login, not by a URL anybody who guesses it can open.
     *
     * Null means «no photo we may have», and that covers two different things on purpose:
     * the person has none, or their «Фото профілю» is set to «Мої контакти», which hides it
     * from the bot as firmly as from a stranger. Measured on prod 08.09.2026: of 80 linked
     * residents the bot could see **36**. There is nothing to fix there — do not go looking
     * for an API that gets past it.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_path = null;

    /**
     * The same photo at the largest size Telegram offers, for the tap-to-enlarge.
     *
     * Kept as a second file rather than serving one big picture everywhere: a page of 25
     * rows draws 25 circles 28 pixels across, and paying ~60 KB apiece for them on the
     * accountant's phone to make a rarely-used click instant is the wrong trade. The big
     * one is fetched by the browser only when somebody actually taps.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_full_path = null;

    /** When we last asked Telegram — so the sync can skip what it looked at this week. */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $photo_checked_at = null;

    public function getPhotoPath(): ?string
    {
        return $this->photo_path;
    }

    public function setPhotoPath(?string $photo_path): self
    {
        $this->photo_path = $photo_path;

        return $this;
    }

    public function getPhotoFullPath(): ?string
    {
        return $this->photo_full_path;
    }

    public function setPhotoFullPath(?string $path): self
    {
        $this->photo_full_path = $path;

        return $this;
    }

    public function getPhotoCheckedAt(): ?\DateTimeInterface
    {
        return $this->photo_checked_at;
    }

    public function setPhotoCheckedAt(?\DateTimeInterface $at): self
    {
        $this->photo_checked_at = $at;

        return $this;
    }

    /**
     * Two letters for the circle drawn when there is no photo — which is most people.
     *
     * The registry name first: «Конакбаєва Марина» is who the accountant is looking for,
     * and a Telegram nickname («Ника», «bog bog») is not. Falls back to the Telegram name,
     * then to «?» — never to an empty circle, which reads as a broken image.
     */
    public function getInitials(): string
    {
        // `isset()` rather than the getters: these are typed properties with no default,
        // so on an entity that was built rather than hydrated — a test, a fresh /start
        // before the first flush — reading one throws instead of returning null. The
        // codebase has been bitten by that before.
        $first = isset($this->first_name) ? (string)$this->first_name : '';
        $last = isset($this->last_name) ? (string)$this->last_name : '';

        $source = trim((string)($this->full_name ?: trim($first . ' ' . $last)));

        if ($source === '' && isset($this->username)) {
            $source = (string)$this->username;
        }

        $words = preg_split('/\s+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
        }

        return $letters !== '' ? $letters : '?';
    }

    #[NotBlank]
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id')]
    private ?Account $account = null;

    public function __construct()
    {
        $this->account = null;
        $this->scheduledSet = new ArrayCollection();
        $this->phone_number = null;
        $this->chatId = null;
        $this->additional_phones = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): TelegramUser
    {
        $this->id = $id;

        return $this;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegram_id;
    }

    public function setTelegramId(?string $telegram_id): TelegramUser
    {
        $this->telegram_id = $telegram_id;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phone_number;
    }

    public function setPhoneNumber(?string $phone_number): TelegramUser
    {
        $this->phone_number = $phone_number;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->first_name;
    }

    public function setFirstName(?string $first_name): TelegramUser
    {
        $this->first_name = $first_name;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->last_name;
    }

    public function setLastName(?string $last_name): TelegramUser
    {
        $this->last_name = $last_name;

        return $this;
    }

    /**
     * `?? null` because the property is typed and has no default: reading it on an entity
     * that was never persisted throws instead of returning null. Every other nullable
     * reader on this class already does it (see getDisplayName, and the created_at trait);
     * this one did not, and a menu that named the household fell over on the first row
     * built in memory.
     */
    public function getUsername(): ?string
    {
        return $this->username ?? null;
    }

    public function setUsername(?string $username): TelegramUser
    {
        $this->username = $username;

        return $this;
    }

    public function getLanguageCode(): string
    {
        return $this->language_code;
    }

    public function setLanguageCode(string $language_code): TelegramUser
    {
        $this->language_code = $language_code;

        return $this;
    }

    public function getChatId(): ?string
    {
        return $this->chatId;
    }

    public function setChatId(?string $chatId): TelegramUser
    {
        $this->chatId = $chatId;

        return $this;
    }

    public function getAdditionalPhones(): array
    {
        return $this->additional_phones ?: [];
    }

    public function setAdditionalPhones(?array $additional_phones): TelegramUser
    {
        $this->additional_phones = $additional_phones ?: [];

        return $this;
    }

    public const ROLE_OWNER = 'owner';
    public const ROLE_FAMILY = 'family';
    public const ROLE_TENANT = 'tenant';

    public const ROLES = [
        self::ROLE_OWNER => 'Власник',
        self::ROLE_FAMILY => 'Член сім\'ї',
        self::ROLE_TENANT => 'Орендар',
    ];

    public function getFullName(): ?string
    {
        return $this->full_name;
    }

    public function setFullName(?string $full_name): TelegramUser
    {
        $full_name = $full_name === null ? null : trim($full_name);
        $this->full_name = ($full_name === null || $full_name === '') ? null : mb_substr($full_name, 0, 180);

        return $this;
    }

    /** The registry name when the ОСББ knows it, the Telegram one otherwise. */
    public function getDisplayName(): string
    {
        if ($this->full_name !== null && $this->full_name !== '') {
            return $this->full_name;
        }

        $name = trim(sprintf('%s %s', (string)($this->first_name ?? ''), (string)($this->last_name ?? '')));

        return $name !== '' ? $name : 'без імені';
    }

    /**
     * Whether the ОСББ's arrears on this flat are this person's business.
     *
     * A tenant is linked to the account so the bot knows which flat they live in — that is
     * what gets them into the house chat, lets them book the альтанка and lets them report
     * a broken lift. It does not make the owner's debt theirs, and until 08.09.2026 the bot
     * told them it did: the figure sat beside their особовий рахунок on every /start, the
     * board marked it «📌 Ваша квартира», and the monthly reminder addressed them by it.
     *
     * The public debtors' board is **not** hidden from them, deliberately: it names every
     * flat in the house to every resident, so their neighbour on the fifth floor reads the
     * same line. Hiding it would leave a tenant less informed than anybody else while
     * protecting nothing. What is switched off is the bot asserting that the debt is *his*.
     *
     * `role` is descriptive everywhere else in this bot — this is the one thing it decides.
     */
    public function owesForTheFlat(): bool
    {
        return ($this->role ?? null) !== self::ROLE_TENANT;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = isset(self::ROLES[(string)$role]) ? (string)$role : null;

        return $this;
    }

    public function getRoleLabel(): string
    {
        return self::ROLES[$this->role] ?? 'Не вказано';
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): TelegramUser
    {
        $this->account = $account;

        return $this;
    }

    public function concatNameInfo(): string
    {
        return sprintf('%s %s %s %s', $this->phone_number, $this->first_name, $this->last_name, $this->username);
    }
}
