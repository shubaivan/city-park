<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Account
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $account_number = null;

    #[ORM\Column(length: 255)]
    private ?string $apartment_number = null;

    #[ORM\Column(length: 255)]
    private ?string $house_number = null;

    #[ORM\Column(length: 255)]
    private ?string $street = null;

    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => false])]
    private ?bool $is_active = false;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true, options: ['default' => 0])]
    private ?string $debt = '0';

    /**
     * When `debt` was last written by an import — NOT when it last changed.
     *
     * The number itself carries no date, and it only ever moves when the accountant
     * uploads a fresh file (there is no live feed from the ОСББ books). Published
     * unqualified, a stale figure names as a debtor somebody who paid three weeks ago,
     * so the debtors' board renders this as "станом на …" on every screen. It dates the
     * figures rather than expiring them: the board follows the accountant's uploads, and
     * only the complete absence of one keeps it silent.
     *
     * Stamped inside setDebt() rather than at the call sites: two import paths exist
     * (debt:import-file and /admin/debt/upload, each with a main loop and a
     * not-in-file reset loop), and a forgotten stamp in one of them is a silent lie
     * on a public board rather than a visible bug.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $debt_updated_at = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $area = null;

    /**
     * When set in the future, this account is under a time-boxed community vote-block
     * (BlockVoteCampaign). The block-vote:tally cron auto-restores is_active once this
     * instant passes. NULL means no active vote-block.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $blocked_until = null;

    /**
     * How many times the community has voted to block this account (every passed
     * BlockVoteCampaign increments it, even if the account was already blocked at the
     * time). A repeat-offender tally surfaced across the admin panel and the bot.
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $vote_block_count = 0;

    /**
     * Admin-linked owner group: when set, this account shares booking limits and
     * debt aggregation with every other account having the same `owner_group_id`.
     * NULL means "ungrouped" (treated as a group of one via getEffectiveGroupId()).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $owner_group_id = null;

    /**
     * What kind of property this is: a flat, a parking space or a storage room.
     *
     * Derived from the особовий рахунок for years (`getUnitTypeDigit()`: the third digit of
     * Q-P-T-NNN — 0 apartment, 5 storage, 7 parking) and never written down. That worked
     * until it did not:
     *
     * - the formula reads a *position*, so a mistyped rahunok reads as the wrong type
     *   rather than as an error — `42076` is five digits, and its third character is the
     *   `0` of what should have been `420076`;
     * - six of the eight non-flat accounts on prod carry a bare number in
     *   `apartment_number`, so nothing else in the row says what it is;
     * - and there is no way for an admin to correct one, because there was nothing to
     *   correct — the answer was recomputed from the number every time.
     *
     * So the type is now stored, seeded from the formula (which is right for every current
     * row), and editable. The formula stays as the fallback for a row that has none.
     */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $unit_type = null;

    #[ORM\OneToMany(targetEntity: TelegramUser::class, mappedBy: 'account', cascade: ["persist"])]
    private Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->is_active = false;
        $this->debt = '0';
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountNumber(): ?string
    {
        return $this->account_number;
    }

    public function setAccountNumber(string $account_number): static
    {
        $this->account_number = $account_number;

        return $this;
    }

    public function getApartmentNumber(): ?string
    {
        return $this->apartment_number;
    }

    public function setApartmentNumber(string $apartment_number): static
    {
        $this->apartment_number = $apartment_number;

        return $this;
    }

    public function getHouseNumber(): ?string
    {
        return $this->house_number;
    }

    public function setHouseNumber(string $house_number): static
    {
        $this->house_number = $house_number;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $is_active): Account
    {
        $this->is_active = $is_active;

        return $this;
    }

    public function getDebt(): ?string
    {
        return $this->debt;
    }

    public function setDebt(?string $debt): static
    {
        $this->debt = $debt;
        $this->debt_updated_at = new \DateTimeImmutable();

        return $this;
    }

    public function getDebtUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->debt_updated_at;
    }

    /**
     * Only for backfilling the column on accounts imported before it existed.
     */
    public function setDebtUpdatedAt(?\DateTimeImmutable $at): static
    {
        $this->debt_updated_at = $at;

        return $this;
    }

    public function getArea(): ?string
    {
        return $this->area;
    }

    public function setArea(?string $area): static
    {
        $this->area = $area;

        return $this;
    }

    public function getBlockedUntil(): ?\DateTime
    {
        return $this->blocked_until;
    }

    public function setBlockedUntil(?\DateTime $blocked_until): static
    {
        $this->blocked_until = $blocked_until;

        return $this;
    }

    /**
     * True while a community vote-block is still in force (blocked_until is in the future).
     * Used by debt/photo unblock paths to avoid prematurely lifting a vote-block.
     */
    public function isUnderVoteBlock(): bool
    {
        return $this->blocked_until !== null && $this->blocked_until > new \DateTime();
    }

    public function getVoteBlockCount(): int
    {
        return $this->vote_block_count;
    }

    public function setVoteBlockCount(int $vote_block_count): static
    {
        $this->vote_block_count = $vote_block_count;

        return $this;
    }

    public function incrementVoteBlockCount(): static
    {
        $this->vote_block_count++;

        return $this;
    }

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getOwnerGroupId(): ?int
    {
        return $this->owner_group_id;
    }

    public function setOwnerGroupId(?int $owner_group_id): static
    {
        $this->owner_group_id = $owner_group_id;

        return $this;
    }

    /**
     * Identifier of the owner group this account participates in.
     * Returns the explicit owner_group_id if set, otherwise the account's own id —
     * so every account is "in a group" (a group of one by default) and aggregation
     * queries can join on this value without special-casing nulls.
     */
    public function getEffectiveGroupId(): int
    {
        return $this->owner_group_id ?? (int)$this->id;
    }

    /**
     * Type digit per ОСББ numbering scheme: queue-entrance-TYPE-NNN encoded in
     * the особовий рахунок (account_number). 0 = apartment, 5 = storage
     * (комірка), 7 = parking. Returns null if account_number is shorter than
     * 3 digits or its 3rd digit isn't a recognised type.
     */
    public function getUnitTypeDigit(): ?int
    {
        $digits = preg_replace('/\D+/', '', (string)$this->account_number);
        if (strlen($digits) < 3) {
            return null;
        }
        $d = (int)$digits[2];
        return in_array($d, [0, 5, 7], true) ? $d : null;
    }

    public const UNIT_APARTMENT = 'apartment';
    public const UNIT_PARKING = 'parking';
    public const UNIT_STORAGE = 'storage';

    public const UNIT_TYPES = [
        self::UNIT_APARTMENT => '🏠 Квартира',
        self::UNIT_PARKING => '🚗 Паркомісце',
        self::UNIT_STORAGE => '📦 Комірчина',
    ];

    /**
     * The stored type when there is one, otherwise the old derivation.
     *
     * Everything asking "what kind of unit is this" goes through here, so correcting one
     * row in the admin panel corrects the label on the debtors' board, the complaint posted
     * to the residents' chat and the right to book the pavilion, all at once.
     */
    public function getUnitType(): string
    {
        if ($this->unit_type !== null && isset(self::UNIT_TYPES[$this->unit_type])) {
            return $this->unit_type;
        }

        return $this->deriveUnitType();
    }

    /** The raw stored value — null means "never set, still derived". */
    public function getStoredUnitType(): ?string
    {
        return $this->unit_type;
    }

    public function setUnitType(?string $type): static
    {
        $this->unit_type = ($type !== null && isset(self::UNIT_TYPES[$type])) ? $type : null;

        return $this;
    }

    public function getUnitTypeLabel(): string
    {
        return self::UNIT_TYPES[$this->getUnitType()];
    }

    /**
     * The historical rule, kept as the seed and the fallback: the third digit of the
     * особовий рахунок, plus a free-text check for legacy rows that spell it out.
     */
    public function deriveUnitType(): string
    {
        $value = mb_strtolower((string)$this->apartment_number, 'UTF-8');

        foreach (['кладов', 'комірчина', 'комирчина', 'storage'] as $needle) {
            if (str_contains($value, $needle)) {
                return self::UNIT_STORAGE;
            }
        }

        return match ($this->getUnitTypeDigit()) {
            5 => self::UNIT_STORAGE,
            7 => self::UNIT_PARKING,
            default => self::UNIT_APARTMENT,
        };
    }

    public function isApartment(): bool
    {
        return $this->getUnitType() === self::UNIT_APARTMENT;
    }

    public function isParking(): bool
    {
        return $this->getUnitType() === self::UNIT_PARKING;
    }

    public function isStorage(): bool
    {
        return $this->getUnitType() === self::UNIT_STORAGE;
    }

    /**
     * Whether this account is entitled to book the pavilion based on unit type.
     * Apartments (0) and parking (7) qualify; storage (5) does not — its owners
     * don't pay the yard-maintenance fee. Unparseable legacy rows are allowed
     * unless they match a storage keyword, preserving prior behaviour.
     */
    public function canBookPavilion(): bool
    {
        return !$this->isStorage();
    }
}
