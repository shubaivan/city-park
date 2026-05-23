<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\PavilionPhotoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PavilionPhotoRepository::class)]
#[ORM\Index(name: 'pp_session_idx', columns: ['account_id', 'pavilion', 'session_start_at'], options: ['unique' => true])]
#[ORM\Index(name: 'pp_created_idx', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks()]
class PavilionPhoto
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'uploader_telegram_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $uploader = null;

    #[ORM\Column(type: 'integer', nullable: false)]
    private int $pavilion;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $session_start_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $session_end_at;

    #[ORM\Column(type: 'string', length: 512, nullable: false)]
    private string $file_path;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $telegram_file_id = null;

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

    public function getUploader(): ?TelegramUser
    {
        return $this->uploader;
    }

    public function setUploader(?TelegramUser $uploader): self
    {
        $this->uploader = $uploader;
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

    public function getFilePath(): string
    {
        return $this->file_path;
    }

    public function setFilePath(string $file_path): self
    {
        $this->file_path = $file_path;
        return $this;
    }

    public function getTelegramFileId(): ?string
    {
        return $this->telegram_file_id;
    }

    public function setTelegramFileId(?string $telegram_file_id): self
    {
        $this->telegram_file_id = $telegram_file_id;
        return $this;
    }
}
