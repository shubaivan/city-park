<?php

namespace App\Entity;

use App\Repository\ServiceStaffRepository;
use App\Service\PhoneKey;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody who works for the house and is not a resident — today only the cleaning crew
 * («Клінінг», Иван, 16.11.2026).
 *
 * Recognised by phone, the same way an expected resident is: the ОСББ writes the number
 * down, and when it shares itself with the bot the Telegram id is stamped here. They get
 * no особовий рахунок, never get into the residents' chat (ResidentChatService::mayJoin()
 * knows nothing about this table, on purpose) and see a two-button menu: «Головне» and
 * «📷 Фотозвіт». Their photos are reposted by the bot into the «🧹 Прибирання» topic.
 */
#[ORM\Entity(repositoryClass: ServiceStaffRepository::class)]
#[ORM\Table(name: 'service_staff')]
#[ORM\UniqueConstraint(name: 'service_staff_phone_key_uniq', columns: ['phone_key'])]
#[ORM\Index(name: 'service_staff_tg_idx', columns: ['telegram_id'])]
class ServiceStaff
{
    public const ROLE_CLEANING = 'cleaning';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $phone = '';

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $phone_key = '';

    /** The signature on the chat post: «🧹 Клінінг · …». Never a person's name. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $label = 'Клінінг';

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $role = self::ROLE_CLEANING;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** Stamped when the number first shares itself with the bot. */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $telegram_id = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $created_by = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $created_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $linked_at = null;

    public function __construct(string $phone, string $label = 'Клінінг', string $role = self::ROLE_CLEANING, ?string $createdBy = null)
    {
        $this->phone = trim($phone);
        $this->phone_key = PhoneKey::of($phone);
        $this->label = trim($label) !== '' ? trim($label) : 'Клінінг';
        $this->role = $role;
        $this->created_by = $createdBy;
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getPhoneKey(): string
    {
        return $this->phone_key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = trim($label) !== '' ? trim($label) : $this->label;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegram_id;
    }

    public function link(string $telegramId): self
    {
        $this->telegram_id = $telegramId;
        $this->linked_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));

        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->created_by;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function getLinkedAt(): ?\DateTime
    {
        return $this->linked_at;
    }
}
