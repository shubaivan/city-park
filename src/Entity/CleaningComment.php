<?php

namespace App\Entity;

use App\Repository\CleaningCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message written in the «🧹 Прибирання» topic, held until it is relayed to the
 * cleaning crew's private chats (Иван, 16.11.2026).
 *
 * **Nothing about the author is stored** — no user id, name, flat or phone. The crew gets
 * the words and the photo, never who wrote them, and a row that never knew the author
 * cannot leak it later. Unique on the chat message: Telegram retries a webhook it did not
 * like, and a retried comment must not reach the crew twice.
 */
#[ORM\Entity(repositoryClass: CleaningCommentRepository::class)]
#[ORM\Table(name: 'cleaning_comment')]
#[ORM\UniqueConstraint(name: 'cc_message_uniq', columns: ['chat_message_id'])]
#[ORM\Index(name: 'cc_relayed_idx', columns: ['relayed_at'])]
class CleaningComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $chat_message_id;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $media_group_id = null;

    /** Already stripped of @mentions, links to people and phone numbers. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    /** The largest size of the photo, if the message had one. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $file_id = null;

    /** When the comment is a reply to a photo report: the time that report was posted. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $report_at = null;

    /** The message's own time in the chat. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $created_at;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $relayed_at = null;

    public function __construct(
        int $chatMessageId,
        ?string $text,
        ?string $fileId,
        ?string $mediaGroupId,
        ?\DateTimeInterface $reportAt,
        \DateTimeInterface $createdAt,
    ) {
        $kyiv = new \DateTimeZone('Europe/Kyiv');
        $text = $text !== null ? trim($text) : null;

        $this->chat_message_id = $chatMessageId;
        $this->text = $text === '' ? null : $text;
        $this->file_id = $fileId !== '' ? $fileId : null;
        $this->media_group_id = $mediaGroupId !== '' ? $mediaGroupId : null;
        $this->report_at = $reportAt !== null ? \DateTime::createFromInterface($reportAt)->setTimezone($kyiv) : null;
        $this->created_at = \DateTime::createFromInterface($createdAt)->setTimezone($kyiv);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatMessageId(): int
    {
        return $this->chat_message_id;
    }

    public function getMediaGroupId(): ?string
    {
        return $this->media_group_id;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getFileId(): ?string
    {
        return $this->file_id;
    }

    public function getReportAt(): ?\DateTime
    {
        return $this->report_at;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function getRelayedAt(): ?\DateTime
    {
        return $this->relayed_at;
    }

    public function markRelayed(): self
    {
        $this->relayed_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));

        return $this;
    }
}
