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

    public function getUsername(): ?string
    {
        return $this->username;
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
