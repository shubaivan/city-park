<?php

namespace App\Entity;

use App\Repository\CleaningReportPhotoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One photo of a cleaning report, held until it is reposted into the residents' chat.
 *
 * An album reaches the webhook as one update per photo, so the photos are written here
 * and a delayed Messenger job posts the whole `media_group_id` as one sendMediaGroup.
 * Unique on (chat, message): Telegram retries a webhook it did not like, and a retried
 * photo must not be posted twice.
 */
#[ORM\Entity(repositoryClass: CleaningReportPhotoRepository::class)]
#[ORM\Table(name: 'cleaning_report_photo')]
#[ORM\UniqueConstraint(name: 'crp_message_uniq', columns: ['chat_id', 'message_id'])]
#[ORM\Index(name: 'crp_group_idx', columns: ['media_group_id'])]
#[ORM\Index(name: 'IDX_CRP_STAFF', columns: ['staff_id'])]
class CleaningReportPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceStaff::class)]
    #[ORM\JoinColumn(name: 'staff_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ServiceStaff $staff = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $chat_id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $message_id;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $media_group_id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $file_id;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $caption = null;

    /** The signature, copied at the time of sending so a later rename does not rewrite history. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $label;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $created_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $posted_at = null;

    public function __construct(
        ?ServiceStaff $staff,
        string $chatId,
        int $messageId,
        string $fileId,
        ?string $caption,
        ?string $mediaGroupId,
        string $label,
    ) {
        $this->staff = $staff;
        $this->chat_id = $chatId;
        $this->message_id = $messageId;
        $this->file_id = $fileId;
        $caption = $caption !== null ? trim($caption) : null;
        $this->caption = $caption === '' ? null : $caption;
        $this->media_group_id = $mediaGroupId !== '' ? $mediaGroupId : null;
        $this->label = $label;
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStaff(): ?ServiceStaff
    {
        return $this->staff;
    }

    public function getChatId(): string
    {
        return $this->chat_id;
    }

    public function getMessageId(): int
    {
        return $this->message_id;
    }

    public function getMediaGroupId(): ?string
    {
        return $this->media_group_id;
    }

    public function getFileId(): string
    {
        return $this->file_id;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function getPostedAt(): ?\DateTime
    {
        return $this->posted_at;
    }

    public function unmarkPosted(): self
    {
        $this->posted_at = null;

        return $this;
    }

    public function markPosted(): self
    {
        $this->posted_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));

        return $this;
    }
}
