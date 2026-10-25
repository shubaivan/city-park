<?php

namespace App\Entity;

use App\Repository\BookingLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing that happened to a booked hour: it was booked, the rules refused it, or it was
 * removed.
 *
 * `scheduled_set` is deleted outright, so before this table a removed booking left nothing
 * behind but a gap in the id sequence — and a refused one left the same gap, because the id
 * is drawn at `persist()`, before the validator runs. On 24.10.2026 a resident of буд. 21,
 * кв. 118 booked 13:00–15:00 for the weekend, and the next day there was no trace of it:
 * four ids gone and no way to say whether he deleted the hours while being shown how, or
 * whether they were never accepted. This is the answer to «куди поділась моя бронь».
 *
 * A journal rather than a soft delete on purpose: a `deleted_at` on `scheduled_set` would
 * have to be filtered by every query that reads it — the limits, the guard board, the photo
 * cron, the history — and the one that forgot would count a deleted hour against somebody's
 * limit or show it to the guard.
 *
 * Every reference is SET NULL or a bare integer, and the person and the flat are kept as
 * labels: the row must still read like a fact after the booking is gone (which, for a
 * deletion, it already is) and after the person is unlinked. Nothing here is edited.
 */
#[ORM\Entity(repositoryClass: BookingLogRepository::class)]
#[ORM\Table(name: 'booking_log')]
#[ORM\Index(name: 'bl_created_idx', columns: ['created_at'])]
#[ORM\Index(name: 'bl_scheduled_idx', columns: ['scheduled_at'])]
class BookingLog
{
    public const ACTION_CREATED = 'created';
    public const ACTION_REFUSED = 'refused';
    public const ACTION_DELETED = 'deleted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $action = self::ACTION_CREATED;

    /** Who pressed the button. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $actor = null;

    #[ORM\Column(type: Types::STRING, length: 160, nullable: false)]
    private string $actor_label = '';

    /** The object the hour was booked for. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $account = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: false)]
    private string $place_label = '';

    #[ORM\Column(type: Types::INTEGER, nullable: false)]
    private int $pavilion = 1;

    /** The hour that was booked, refused or removed. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $scheduled_at;

    /** `scheduled_set.id`. Not a FK: the row it names is deleted on removal, and a refused one never existed. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $scheduled_set_id = null;

    /** What the resident was told on a refusal, without the markup. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    public function __construct()
    {
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $this->scheduled_at = clone $this->created_at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getActor(): ?TelegramUser
    {
        return $this->actor;
    }

    public function setActor(?TelegramUser $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getActorLabel(): string
    {
        return $this->actor_label;
    }

    public function setActorLabel(string $label): static
    {
        $this->actor_label = mb_substr($label, 0, 160);

        return $this;
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

    public function getPlaceLabel(): string
    {
        return $this->place_label;
    }

    public function setPlaceLabel(string $label): static
    {
        $this->place_label = mb_substr($label, 0, 120);

        return $this;
    }

    public function getPavilion(): int
    {
        return $this->pavilion;
    }

    public function setPavilion(int $pavilion): static
    {
        $this->pavilion = $pavilion;

        return $this;
    }

    public function getScheduledAt(): \DateTime
    {
        return $this->scheduled_at;
    }

    public function setScheduledAt(\DateTime $at): static
    {
        $this->scheduled_at = $at;

        return $this;
    }

    public function getScheduledSetId(): ?int
    {
        return $this->scheduled_set_id;
    }

    public function setScheduledSetId(?int $id): static
    {
        $this->scheduled_set_id = $id;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }
}
