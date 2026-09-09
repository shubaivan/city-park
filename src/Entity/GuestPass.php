<?php

namespace App\Entity;

use App\Repository\GuestPassRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pass a flat hands to the people working in it — a repair crew, a delivery, a fitter.
 *
 * The house had nothing for this: the crew arrives at eight, the guard has no way to tell
 * an expected builder from anybody else with a toolbox, and the only available check was
 * «нам сказали, що нас чекають у 85-й». Иван asked for it on 09.09.2026.
 *
 * **One code per crew, switched on for a day at a time.** The obvious shape — a fresh pass
 * every morning — puts the work in the wrong place: the resident's taps are cheap, but
 * *re-sending a new picture to the бригадир every day* is not, and on the third morning the
 * бригадир says «просто скажіть охороні» and the whole thing is back to somebody's word.
 * So the picture is minted once and forwarded once; `active_on` is the day the resident
 * switched it on, and a guard scanning it on any other day is told plainly that it is not
 * activated. The lifetime Иван asked for — one day — is exactly what the answer enforces.
 *
 * **Stored, unlike the resident's own QR**, which is a bare HMAC over an account id and
 * needs no row. This one has a day, a name, a revocation and a scan history, and every one
 * of those is a fact somebody will need to look up after the event.
 */
#[ORM\Entity(repositoryClass: GuestPassRepository::class)]
#[ORM\Table(name: 'guest_pass')]
#[ORM\Index(name: 'gp_account_idx', columns: ['account_id'])]
class GuestPass
{
    /**
     * Live passes per flat. A repair crew, a delivery and a fitter is the realistic top of
     * what a flat has running at once; twenty is somebody handing out passes to the street.
     */
    public const MAX_PER_ACCOUNT = 3;

    /** What the resident writes on it: «Бригада, ремонт», «Доставка меблів». */
    public const MAX_LABEL = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The flat that vouches for these people. Never null: a pass with no host is nobody's. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    /**
     * Who issued it. SET NULL: the pass is the flat's, and it must survive the person being
     * unlinked — but while they are there, «хто впустив» has a name.
     */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'issued_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $issuedBy = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: false)]
    private string $label = '';

    /**
     * The day it is switched on for, in Kyiv. Null means «not activated», which is what a
     * pass is on every day its host did not press the button.
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $active_on = null;

    /** Set once and never unset: a revoked pass is dead even if the picture still exists. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $revoked_at = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $last_scanned_at = null;

    #[ORM\Column(type: Types::INTEGER, nullable: false, options: ['default' => 0])]
    private int $scans = 0;

    public function __construct()
    {
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

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getIssuedBy(): ?TelegramUser
    {
        return $this->issuedBy;
    }

    public function setIssuedBy(?TelegramUser $issuedBy): static
    {
        $this->issuedBy = $issuedBy;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = mb_substr(trim($label), 0, self::MAX_LABEL);

        return $this;
    }

    public function getActiveOn(): ?\DateTime
    {
        return $this->active_on;
    }

    public function setActiveOn(?\DateTime $day): static
    {
        $this->active_on = $day;

        return $this;
    }

    public function getRevokedAt(): ?\DateTime
    {
        return $this->revoked_at;
    }

    public function revoke(): static
    {
        $this->revoked_at ??= new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));

        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function getLastScannedAt(): ?\DateTime
    {
        return $this->last_scanned_at;
    }

    public function getScans(): int
    {
        return $this->scans;
    }

    public function countScan(\DateTime $at): static
    {
        $this->scans++;
        $this->last_scanned_at = $at;

        return $this;
    }

    /** Switched on for the day this instant falls in. */
    public function isActiveOn(\DateTimeInterface $now): bool
    {
        return !$this->isRevoked()
            && $this->active_on !== null
            && $this->active_on->format('Y-m-d') === $now->format('Y-m-d');
    }
}
