<?php

namespace App\Entity;

use App\Repository\AccountStatusLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountStatusLogRepository::class)]
#[ORM\Table(name: 'account_status_log')]
#[ORM\Index(name: 'asl_account_created_idx', columns: ['account_id', 'created_at'])]
class AccountStatusLog
{
    public const SOURCE_ADMIN              = 'admin';
    public const SOURCE_DEBT_IMPORT        = 'debt_import';
    public const SOURCE_DEBT_RECOMPUTE     = 'debt_recompute';
    public const SOURCE_PHOTO_CHECK        = 'photo_check';
    public const SOURCE_PHOTO_ATTACH       = 'photo_attach';
    public const SOURCE_PHOTO_FORGIVE      = 'photo_forgive';
    public const SOURCE_PHOTO_BULK_UNBLOCK = 'photo_bulk_unblock';
    public const SOURCE_COMMUNITY_VOTE     = 'community_vote';
    public const SOURCE_VOTE_AUTO_UNBLOCK  = 'vote_auto_unblock';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\Column(type: 'boolean', nullable: false)]
    private bool $old_active;

    #[ORM\Column(type: 'boolean', nullable: false)]
    private bool $new_active;

    /** Null for cron/system writes; admin login identifier for manual changes. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $actor_username = null;

    /** One of the SOURCE_* constants. Identifies which code path flipped is_active. */
    #[ORM\Column(type: 'string', length: 32, nullable: false)]
    private string $source;

    /** Short machine-readable cause: 'debt' / 'photo' / 'other' / 'manual' / etc. */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $reason_code = null;

    /** Optional free-form context (e.g. debt amount, photo request id). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason_text = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): self
    {
        $this->account = $account;
        return $this;
    }

    public function getOldActive(): bool
    {
        return $this->old_active;
    }

    public function setOldActive(bool $v): self
    {
        $this->old_active = $v;
        return $this;
    }

    public function getNewActive(): bool
    {
        return $this->new_active;
    }

    public function setNewActive(bool $v): self
    {
        $this->new_active = $v;
        return $this;
    }

    public function getActorUsername(): ?string
    {
        return $this->actor_username;
    }

    public function setActorUsername(?string $v): self
    {
        $this->actor_username = $v;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $v): self
    {
        $this->source = $v;
        return $this;
    }

    public function getReasonCode(): ?string
    {
        return $this->reason_code;
    }

    public function setReasonCode(?string $v): self
    {
        $this->reason_code = $v;
        return $this;
    }

    public function getReasonText(): ?string
    {
        return $this->reason_text;
    }

    public function setReasonText(?string $v): self
    {
        $this->reason_text = $v;
        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTime $v): self
    {
        $this->created_at = $v;
        return $this;
    }
}
