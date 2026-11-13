<?php

namespace App\Entity;

use App\Repository\GateEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One car or person through the gate: «🚗 В'їзд» or «🚙 Виїзд», pressed by the guard under a
 * valid QR scan (Иван, 13.11.2026).
 *
 * The QR scan log answers «хто кого перевіряв»; this answers «хто заїхав і хто виїхав», which
 * the scan alone cannot — the same code is shown on the way in and on the way out.
 *
 * - **One event per scan** (unique `qr_scan_id`). The button is idempotent: a second press,
 *   a double tap or Telegram re-delivering the callback finds the row already there and says
 *   «вже зафіксовано», never a second line. A new crossing is a new scan.
 * - **Labels, not only keys**, the `QrScan` rule: the guard, the flat, the person and the car
 *   are kept as text so the row still reads the same after anyone is unlinked or removed.
 * - `gate` is which gate, when that is known. There is one post today, so it stays null; the
 *   column is there so that a second gate does not need a migration to be told apart.
 *
 * Read on `/admin/scans`, GET only — a log that can be edited is not a log.
 */
#[ORM\Entity(repositoryClass: GateEventRepository::class)]
#[ORM\Table(name: 'gate_event')]
#[ORM\Index(name: 'ge_created_idx', columns: ['created_at'])]
#[ORM\Index(name: 'ge_guard_idx', columns: ['guard_id'])]
#[ORM\Index(name: 'ge_subject_idx', columns: ['subject_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_gate_event_scan', columns: ['qr_scan_id'])]
class GateEvent
{
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: false)]
    private string $direction = self::DIRECTION_IN;

    /** The scan the buttons hung under. SET NULL: the event outlives a pruned scan. */
    #[ORM\ManyToOne(targetEntity: QrScan::class)]
    #[ORM\JoinColumn(name: 'qr_scan_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?QrScan $qrScan = null;

    /** `resident` or `guest`, copied from the scan. */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $kind = QrScan::KIND_RESIDENT;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'guard_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $guard = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: false)]
    private string $guard_label = '';

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'subject_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $subjectAccount = null;

    /** «буд. 19, кв. 85». */
    #[ORM\Column(type: Types::STRING, length: 120, nullable: false)]
    private string $place_label = '';

    /** ПІБ of the household, or the guest pass's own label. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: false)]
    private string $person_label = '';

    /** The household's car numbers, when it wrote any down. */
    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $car_label = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $guest_pass_id = null;

    /** Which gate, when known. One post today, so null. */
    #[ORM\Column(type: Types::STRING, length: 60, nullable: true)]
    private ?string $gate = null;

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

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): static
    {
        $this->direction = $direction === self::DIRECTION_OUT ? self::DIRECTION_OUT : self::DIRECTION_IN;

        return $this;
    }

    public function isEntry(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }

    /** «🚗 В'їзд» / «🚙 Виїзд» — the words on the button that was pressed. */
    public function getDirectionLabel(): string
    {
        return self::labelFor($this->direction);
    }

    public static function labelFor(string $direction): string
    {
        return $direction === self::DIRECTION_OUT ? "🚙 Виїзд" : "🚗 В'їзд";
    }

    public function getQrScan(): ?QrScan
    {
        return $this->qrScan;
    }

    public function setQrScan(?QrScan $scan): static
    {
        $this->qrScan = $scan;

        return $this;
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

    public function getGuard(): ?TelegramUser
    {
        return $this->guard;
    }

    public function setGuard(?TelegramUser $guard): static
    {
        $this->guard = $guard;

        return $this;
    }

    public function getGuardLabel(): string
    {
        return $this->guard_label;
    }

    public function setGuardLabel(string $label): static
    {
        $this->guard_label = mb_substr($label, 0, 120);

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

    public function getPlaceLabel(): string
    {
        return $this->place_label;
    }

    public function setPlaceLabel(string $label): static
    {
        $this->place_label = mb_substr($label, 0, 120);

        return $this;
    }

    public function getPersonLabel(): string
    {
        return $this->person_label;
    }

    public function setPersonLabel(string $label): static
    {
        $this->person_label = mb_substr($label, 0, 255);

        return $this;
    }

    public function getCarLabel(): ?string
    {
        return $this->car_label;
    }

    public function setCarLabel(?string $label): static
    {
        $label = $label === null ? null : trim($label);
        $this->car_label = $label === null || $label === '' ? null : mb_substr($label, 0, 120);

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

    public function getGate(): ?string
    {
        return $this->gate;
    }

    public function setGate(?string $gate): static
    {
        $this->gate = $gate;

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTime $at): static
    {
        $this->created_at = $at;

        return $this;
    }
}
