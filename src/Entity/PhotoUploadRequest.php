<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\PhotoUploadRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PhotoUploadRequestRepository::class)]
#[ORM\Index(name: 'pur_session_idx', columns: ['account_id', 'pavilion', 'session_start_at'], options: ['unique' => true])]
#[ORM\Index(name: 'pur_open_idx', columns: ['resolved_at'])]
#[ORM\HasLifecycleCallbacks()]
class PhotoUploadRequest
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\Column(type: 'integer', nullable: false)]
    private int $pavilion;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $session_start_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $session_end_at;

    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $reminders_sent = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $last_reminder_at = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $blocked_at = null;

    /**
     * When the "self-upload grace window is almost over" warning was sent (once),
     * fired ~30 min before uploadCutoffAt. Null = not yet warned.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $grace_warning_sent_at = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $resolved_at = null;

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

    public function getPavilion(): int
    {
        return $this->pavilion;
    }

    public function setPavilion(int $pavilion): self
    {
        $this->pavilion = $pavilion;
        return $this;
    }

    public function getSessionStartAt(): \DateTime
    {
        return $this->session_start_at;
    }

    public function setSessionStartAt(\DateTime $session_start_at): self
    {
        $this->session_start_at = $session_start_at;
        return $this;
    }

    public function getSessionEndAt(): \DateTime
    {
        return $this->session_end_at;
    }

    public function setSessionEndAt(\DateTime $session_end_at): self
    {
        $this->session_end_at = $session_end_at;
        return $this;
    }

    public function getRemindersSent(): int
    {
        return $this->reminders_sent;
    }

    public function setRemindersSent(int $reminders_sent): self
    {
        $this->reminders_sent = $reminders_sent;
        return $this;
    }

    public function getLastReminderAt(): ?\DateTime
    {
        return $this->last_reminder_at;
    }

    public function setLastReminderAt(?\DateTime $last_reminder_at): self
    {
        $this->last_reminder_at = $last_reminder_at;
        return $this;
    }

    public function getBlockedAt(): ?\DateTime
    {
        return $this->blocked_at;
    }

    public function setBlockedAt(?\DateTime $blocked_at): self
    {
        $this->blocked_at = $blocked_at;
        return $this;
    }

    public function getGraceWarningSentAt(): ?\DateTime
    {
        return $this->grace_warning_sent_at;
    }

    public function setGraceWarningSentAt(?\DateTime $grace_warning_sent_at): self
    {
        $this->grace_warning_sent_at = $grace_warning_sent_at;
        return $this;
    }

    public function getResolvedAt(): ?\DateTime
    {
        return $this->resolved_at;
    }

    public function setResolvedAt(?\DateTime $resolved_at): self
    {
        $this->resolved_at = $resolved_at;
        return $this;
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null && $this->resolved_at === null;
    }
}
