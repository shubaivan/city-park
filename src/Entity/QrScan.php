<?php

namespace App\Entity;

use App\Repository\QrScanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One scan of a QR code: who read it, whose code it was, when, and what the bot answered.
 *
 * Asked for by Иван the same evening the scan was opened to the house, and it is the half
 * that makes opening it defensible. The moment 457 people can check each other, «хто когось
 * перевіряв» stops being hypothetical — and after anything happens in the yard the first
 * question is «хто їх пустив і коли вони заходили», which nothing else in this bot can
 * answer.
 *
 * **Both sides are nullable and SET NULL.** A scan is a fact about a moment; it must
 * survive either person being unlinked or removed, and the flat is kept as a *label* so
 * the row still reads «буд. 19, кв. 85» afterwards — the same snapshot rule
 * `ComplaintComment.author_label` follows.
 *
 * Read by the four panel logins on `/admin/scans`, GET only. Nothing here is anybody's to
 * change: a security log that can be edited is not a log.
 */
#[ORM\Entity(repositoryClass: QrScanRepository::class)]
#[ORM\Table(name: 'qr_scan')]
#[ORM\Index(name: 'qs_created_idx', columns: ['created_at'])]
class QrScan
{
    /** Somebody's own resident pass. */
    public const KIND_RESIDENT = 'resident';

    /** A pass a flat issued to its builders. */
    public const KIND_GUEST = 'guest';

    /** What the bot answered, so the log says whether the person was let through. */
    public const RESULT_OK = 'ok';
    public const RESULT_NO_BOOKING = 'no_booking';
    public const RESULT_NOT_ACTIVE = 'not_active';
    public const RESULT_REVOKED = 'revoked';
    public const RESULT_UNKNOWN = 'unknown';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $kind = self::KIND_RESIDENT;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $result = self::RESULT_OK;

    /** Who pointed the camera. Null once they are gone from the bot. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'scanned_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $scannedBy = null;

    /** Reads «Ніка (кв. 85)» / «Охорона» — kept as text so the row survives the FK. */
    #[ORM\Column(type: Types::STRING, length: 120, nullable: false)]
    private string $scanned_by_label = '';

    /** Whose code it was. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'subject_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $subjectAccount = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: false)]
    private string $subject_label = '';

    /** The guest pass, when it was one. Not a FK: a revoked pass may be deleted one day. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $guest_pass_id = null;

    /** Whether the guard scanned it — the answer they got was the same either way. */
    #[ORM\Column(type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $by_guard = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    public function __construct()
    {
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function setResult(string $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function getScannedBy(): ?TelegramUser
    {
        return $this->scannedBy;
    }

    public function setScannedBy(?TelegramUser $user): static
    {
        $this->scannedBy = $user;

        return $this;
    }

    public function getScannedByLabel(): string
    {
        return $this->scanned_by_label;
    }

    public function setScannedByLabel(string $label): static
    {
        $this->scanned_by_label = mb_substr($label, 0, 120);

        return $this;
    }

    public function getSubjectAccount(): ?Account
    {
        return $this->subjectAccount;
    }

    public function setSubjectAccount(?Account $account): static
    {
        $this->subjectAccount = $account;

        return $this;
    }

    public function getSubjectLabel(): string
    {
        return $this->subject_label;
    }

    public function setSubjectLabel(string $label): static
    {
        $this->subject_label = mb_substr($label, 0, 120);

        return $this;
    }

    public function getGuestPassId(): ?int
    {
        return $this->guest_pass_id;
    }

    public function setGuestPassId(?int $id): static
    {
        $this->guest_pass_id = $id;

        return $this;
    }

    public function isByGuard(): bool
    {
        return $this->by_guard;
    }

    public function setByGuard(bool $byGuard): static
    {
        $this->by_guard = $byGuard;

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }
}
