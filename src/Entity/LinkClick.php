<?php

namespace App\Entity;

use App\Repository\LinkClickRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One tap on «↗️ Відкрити в боті» under a post in the residents' chat.
 *
 * **This table exists because Telegram will not tell us anything else.** The read list of a
 * message is shown only to whoever sent it, and these are sent by the bot; the Bot API has
 * no read-receipt method at all. So «did anybody look at this» has exactly one available
 * answer, and it is a better one than «seen» would be: a read receipt means somebody
 * scrolled past, a click means they wanted the thing.
 *
 * **Only links are recorded.** Opening the same card from inside the bot writes nothing.
 * The question is «did the chat post work», not «what is this resident reading» — four
 * admins can read this table, and that boundary is the whole reason it is defensible. Do
 * not extend it to card renders for the sake of a bigger number.
 *
 * The user is nullable and SET NULL: a click is a fact about the post, and it must survive
 * the person being unlinked or removed.
 */
#[ORM\Entity(repositoryClass: LinkClickRepository::class)]
#[ORM\Table(name: 'link_click')]
#[ORM\Index(name: 'lc_kind_target_idx', columns: ['kind', 'target_id'])]
#[ORM\Index(name: 'lc_created_idx', columns: ['created_at'])]
class LinkClick
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** DeepLink::KIND_* — which board the post belonged to. */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $kind = '';

    /** The advert / complaint that was opened. Not a FK: three different tables. */
    #[ORM\Column(type: Types::INTEGER, nullable: false)]
    private int $target_id = 0;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $user = null;

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

    public function getTargetId(): int
    {
        return $this->target_id;
    }

    public function setTargetId(int $target_id): static
    {
        $this->target_id = $target_id;

        return $this;
    }

    public function getUser(): ?TelegramUser
    {
        return $this->user;
    }

    public function setUser(?TelegramUser $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }
}
