<?php

namespace App\Entity;

use App\Repository\ExpectedResidentRepository;
use App\Service\PhoneKey;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A phone number the ОСББ says belongs to an object, written down before its owner has
 * ever opened the bot.
 *
 * The bot could already recognise a newcomer by their number — `resolveAccount()` looks
 * the shared contact up among the «умовні власники» of somebody already linked — but that
 * mechanism hangs off a `TelegramUser`, so it only works on an object that **already has a
 * resident in the bot**. Of the ЖК's 966 objects roughly 790 have nobody, and those are
 * precisely the ones that need it: an object with no linked resident receives none of the
 * bot's notices, so its arrears reach nobody at all.
 *
 * Found on 21.09.2026 through буд. 19, кв. 50 — 16 314 грн of arrears, fourteenth in the
 * house, and not one person in the bot to tell. The head of the ОСББ handed over the
 * owner's name and number; there was nowhere to put them, and the only available answer
 * was «нехай натисне /start, а потім Аліна прив'яже руками» — two people and a day of
 * delay for something already known.
 *
 * So the fact is stored where it belongs: on the object, as the ОСББ's own statement that
 * this number is this flat's. When that number finally shares itself with the bot, the
 * link happens on the spot.
 *
 * **The row survives being used.** It is not deleted on the match, it is stamped: the card
 * then says «✅ прийшов 21.09» instead of silently emptying, which is the only way the
 * person who wrote the number down can tell a registration that worked from one that was
 * never typed. Same reading as every other record in this panel.
 */
#[ORM\Entity(repositoryClass: ExpectedResidentRepository::class)]
#[ORM\Table(name: 'expected_resident')]
#[ORM\Index(name: 'er_account_idx', columns: ['account_id'])]
#[ORM\UniqueConstraint(name: 'er_phone_key_uniq', columns: ['phone_key'])]
class ExpectedResident
{
    /** The object the ОСББ says this number belongs to. Never null: a number with no flat is nobody's. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** As it was given to us, so the panel shows the accountant the number she typed. */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: false)]
    private string $phone = '';

    /**
     * What the match actually runs on: the last nine digits, via PhoneKey.
     *
     * Unique across the table on purpose. A person is linked to exactly one Account, so
     * the same number expected on two objects is a question only a human can answer — and
     * answering it by picking the lower id would attach somebody to a flat nobody chose.
     * Several objects of one household are what `owner_group_id` is for.
     */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $phone_key = '';

    /**
     * The name the ОСББ knows them by, when we were given one.
     *
     * The bot holds no owner names — it knows a flat and a phone — so this is usually the
     * only place a real name exists, and it is copied onto the resident when they arrive.
     */
    #[ORM\Column(type: Types::STRING, length: 180, nullable: true)]
    private ?string $full_name = null;

    /** Which panel login wrote it down. «Хто сказав, що це його номер» is the whole provenance. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $created_by = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $claimed_at = null;

    /**
     * Who actually arrived on it. SET NULL, like every other person reference here: the
     * record is about the object and must outlive the person being unlinked or removed.
     */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'claimed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $claimed_by = null;

    public function __construct(Account $account, string $phone, ?string $fullName = null, ?string $createdBy = null)
    {
        $this->account = $account;
        $this->phone = trim($phone);
        $this->phone_key = PhoneKey::of($phone);
        $this->full_name = self::clean($fullName);
        $this->created_by = $createdBy;
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getPhoneKey(): string
    {
        return $this->phone_key;
    }

    public function getFullName(): ?string
    {
        return $this->full_name;
    }

    public function getCreatedBy(): ?string
    {
        return $this->created_by;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function getClaimedAt(): ?\DateTime
    {
        return $this->claimed_at;
    }

    public function getClaimedBy(): ?TelegramUser
    {
        return $this->claimed_by;
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    /**
     * Stamped once and never unstamped.
     *
     * A second /phone from the same person must not re-run the link — they already have
     * their Account by then, and `resolveAccount()` returns it long before reaching here —
     * but the stamp is what makes that a fact on the page rather than an assumption.
     */
    public function claim(TelegramUser $user): void
    {
        if ($this->claimed_at !== null) {
            return;
        }

        $this->claimed_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $this->claimed_by = $user;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : mb_substr($value, 0, 180);
    }
}
