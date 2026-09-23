<?php

namespace App\Entity;

use App\Repository\SmsLogRepository;
use App\Service\PhoneKey;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per SMS the bot tried to send: to whom, when, what, and what came back.
 *
 * Asked for in the same breath as the sending itself (Иван, 21.09.2026) and it is the
 * half that makes the channel defensible. An SMS costs the ОСББ money and lands in a
 * resident's phone without them asking for it — so «кому і коли ми писали» has to be
 * answerable afterwards, by the accountant, without taking anybody's word for it. A
 * provider's own panel is not that answer: it knows a phone number, not which flat it
 * was, and it is a different login that not everybody here has.
 *
 * **The person is kept as a label, not only as a foreign key.** `place_label` and `phone`
 * are snapshots, like `ComplaintComment.author_label` and `QrScan`: the row still reads
 * like a fact about a household after the resident is unlinked, moved to another flat or
 * removed entirely. A log that becomes «— , —» when somebody is edited is not a log.
 *
 * **A failure is written down exactly like a success.** The interesting rows are the ones
 * that did not arrive: a wrong number, an exhausted balance, a sender name the operators
 * have not approved yet. Those are invisible from the bot's side unless they are stored.
 */
#[ORM\Entity(repositoryClass: SmsLogRepository::class)]
#[ORM\Table(name: 'sms_log')]
#[ORM\Index(name: 'sms_created_idx', columns: ['created_at'])]
#[ORM\Index(name: 'sms_phone_key_idx', columns: ['phone_key'])]
class SmsLog
{
    /** Sent and accepted by the provider. */
    public const STATUS_SENT = 'sent';
    /** Refused — by the provider, by the network, or by us before it left. */
    public const STATUS_FAILED = 'failed';
    /** Counted and priced, deliberately not sent (`--dry-run`). */
    public const STATUS_DRY_RUN = 'dry_run';

    /** What the message was for. Kept coarse on purpose — this is a filter, not a taxonomy. */
    public const PURPOSE_DEBT = 'debt';
    public const PURPOSE_ANNOUNCEMENT = 'announcement';
    public const PURPOSE_TEST = 'test';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: false)]
    private string $purpose = self::PURPOSE_TEST;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: false)]
    private string $phone = '';

    /** The same nine digits the rest of the bot matches a person by — see PhoneKey. */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $phone_key = '';

    /**
     * Which flat this was about, as text. A snapshot: the FK below can go, this cannot.
     */
    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $place_label = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $user = null;

    /** Stored in full. An SMS is 70 characters; there is nothing to truncate and every word of it was ours. */
    #[ORM\Column(type: Types::TEXT, nullable: false)]
    private string $text = '';

    /** How many SMS the provider will charge for — 1 unless the text overflowed. */
    #[ORM\Column(type: Types::INTEGER, nullable: false)]
    private int $parts = 1;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $status = self::STATUS_SENT;

    /** The provider's id, which is what a delivery-status query is made with later. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $provider_id = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $error = null;

    /** An admin login, or the name of the cron that sent it. «Хто це надіслав» has one answer. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $sent_by = null;

    public function __construct(string $phone, string $text, string $purpose)
    {
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $this->phone = trim($phone);
        $this->phone_key = PhoneKey::of($phone);
        $this->text = $text;
        $this->purpose = $purpose;
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTime { return $this->created_at; }
    public function getPurpose(): string { return $this->purpose; }
    public function getPhone(): string { return $this->phone; }
    public function getPhoneKey(): string { return $this->phone_key; }
    public function getPlaceLabel(): ?string { return $this->place_label; }
    public function getAccount(): ?Account { return $this->account; }
    public function getUser(): ?TelegramUser { return $this->user; }
    public function getText(): string { return $this->text; }
    public function getParts(): int { return $this->parts; }
    public function getStatus(): string { return $this->status; }
    public function getProviderId(): ?string { return $this->provider_id; }
    public function getError(): ?string { return $this->error; }
    public function getSentBy(): ?string { return $this->sent_by; }

    public function isSent(): bool { return $this->status === self::STATUS_SENT; }
    public function isFailed(): bool { return $this->status === self::STATUS_FAILED; }

    public function setParts(int $parts): self { $this->parts = max(1, $parts); return $this; }
    public function setSentBy(?string $by): self { $this->sent_by = $by; return $this; }
    public function setPlaceLabel(?string $label): self { $this->place_label = $label; return $this; }

    public function setRecipient(?Account $account, ?TelegramUser $user): self
    {
        $this->account = $account;
        $this->user = $user;

        if ($this->place_label === null && $account instanceof Account) {
            $this->place_label = $account->getPlaceLabel();
        }

        return $this;
    }

    public function markSent(?string $providerId): self
    {
        $this->status = self::STATUS_SENT;
        $this->provider_id = $providerId;
        $this->error = null;

        return $this;
    }

    /** The error is trimmed to the column, never allowed to throw on its way into the log. */
    public function markFailed(string $error): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error = mb_substr($error, 0, 255);

        return $this;
    }

    public function markDryRun(): self
    {
        $this->status = self::STATUS_DRY_RUN;

        return $this;
    }
}
