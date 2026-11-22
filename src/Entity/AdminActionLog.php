<?php

namespace App\Entity;

use App\Repository\AdminActionLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * «Журнал дій» — one row per thing somebody did that changed the house's data.
 *
 * Why it exists: on 22.11.2026 nobody could say who had put seven flats into one owner
 * group. The panel's info lines went to a fingers_crossed buffer that only reaches a file
 * when an error follows, and nginx keeps fourteen days. This table is the answer that
 * does not depend on either.
 *
 * Three kinds of row:
 * - `request` — every non-GET request under /admin, written by AdminActionSubscriber with
 *   the route and the (cleaned) form fields, so a new button is covered without anybody
 *   remembering to log it;
 * - `change` — a watched field changed, old → new, written by AdminChangeListener on the
 *   Doctrine flush whatever caused it: the panel, the bot or a console command;
 * - `manual` — our own SQL on the server, inserted by hand with actor `sql:<who>`.
 *
 * Rows are written through DBAL (AdminActionJournal), never through this entity: the
 * journal must not ride on somebody else's unit of work. The mapping is here for reading.
 *
 * `account_id` / `prev_account_id` / `user_id` are plain integers, not foreign keys, on
 * purpose: the history of a deleted resident is exactly when it is needed.
 */
#[ORM\Entity(repositoryClass: AdminActionLogRepository::class)]
#[ORM\Table(name: 'admin_action_log')]
#[ORM\Index(columns: ['at'], name: 'aal_at_idx')]
#[ORM\Index(columns: ['account_id'], name: 'aal_account_idx')]
#[ORM\Index(columns: ['prev_account_id'], name: 'aal_prev_account_idx')]
#[ORM\Index(columns: ['user_id'], name: 'aal_user_idx')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'aal_target_idx')]
#[ORM\Index(columns: ['actor'], name: 'aal_actor_idx')]
#[ORM\Index(columns: ['request_id'], name: 'aal_request_idx')]
class AdminActionLog
{
    public const KIND_REQUEST = 'request';
    public const KIND_CHANGE = 'change';
    public const KIND_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Kyiv wall time, like every other timestamp this app writes. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $at;

    #[ORM\Column(length: 16, options: ['default' => 'change'])]
    private string $kind = self::KIND_CHANGE;

    /** `alina`, `main_admin`, `bot:<telegram id>`, `console:<command>`, `sql:ivan`, `system`. */
    #[ORM\Column(length: 80, options: ['default' => ''])]
    private string $actor = '';

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(name: 'user_agent', length: 120, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $route = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $path = null;

    #[ORM\Column(name: 'target_type', length: 32, nullable: true)]
    private ?string $targetType = null;

    #[ORM\Column(name: 'target_id', nullable: true)]
    private ?int $targetId = null;

    #[ORM\Column(name: 'account_id', nullable: true)]
    private ?int $accountId = null;

    #[ORM\Column(name: 'prev_account_id', nullable: true)]
    private ?int $prevAccountId = null;

    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $summary = '';

    /** @var array<string, mixed>|null field => [old, new], or the cleaned form fields of a request */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes = null;

    #[ORM\Column(nullable: true)]
    private ?int $status = null;

    /** Ties the request row to the field changes it caused. */
    #[ORM\Column(name: 'request_id', length: 16, nullable: true)]
    private ?string $requestId = null;

    public function __construct()
    {
        $this->at = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAt(): \DateTimeImmutable { return $this->at; }
    public function getKind(): string { return $this->kind; }
    public function getActor(): string { return $this->actor; }
    public function getIp(): ?string { return $this->ip; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getRoute(): ?string { return $this->route; }
    public function getMethod(): ?string { return $this->method; }
    public function getPath(): ?string { return $this->path; }
    public function getTargetType(): ?string { return $this->targetType; }
    public function getTargetId(): ?int { return $this->targetId; }
    public function getAccountId(): ?int { return $this->accountId; }
    public function getPrevAccountId(): ?int { return $this->prevAccountId; }
    public function getUserId(): ?int { return $this->userId; }
    public function getSummary(): string { return $this->summary; }
    /** @return array<string, mixed>|null */
    public function getChanges(): ?array { return $this->changes; }
    public function getStatus(): ?int { return $this->status; }
    public function getRequestId(): ?string { return $this->requestId; }

    /**
     * Hydrate from a raw row — used by tests and by the in-memory sink, never to write.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $entry = new self();
        $entry->id = isset($row['id']) ? (int)$row['id'] : null;
        $entry->at = $row['at'] instanceof \DateTimeImmutable ? $row['at'] : new \DateTimeImmutable((string)($row['at'] ?? 'now'));
        $entry->kind = (string)($row['kind'] ?? self::KIND_CHANGE);
        $entry->actor = (string)($row['actor'] ?? '');
        $entry->ip = $row['ip'] ?? null;
        $entry->userAgent = $row['user_agent'] ?? null;
        $entry->route = $row['route'] ?? null;
        $entry->method = $row['method'] ?? null;
        $entry->path = $row['path'] ?? null;
        $entry->targetType = $row['target_type'] ?? null;
        $entry->targetId = isset($row['target_id']) ? (int)$row['target_id'] : null;
        $entry->accountId = isset($row['account_id']) ? (int)$row['account_id'] : null;
        $entry->prevAccountId = isset($row['prev_account_id']) ? (int)$row['prev_account_id'] : null;
        $entry->userId = isset($row['user_id']) ? (int)$row['user_id'] : null;
        $entry->summary = (string)($row['summary'] ?? '');
        $changes = $row['changes'] ?? null;
        $entry->changes = is_string($changes) ? json_decode($changes, true) : $changes;
        $entry->status = isset($row['status']) ? (int)$row['status'] : null;
        $entry->requestId = $row['request_id'] ?? null;

        return $entry;
    }

    /** «👩‍💼 alina», «🤖 бот», «⚙️ команда debt:import-file», «🛠 SQL (ivan)». */
    public function getActorLabel(): string
    {
        $actor = $this->actor;

        return match (true) {
            $actor === '' => '—',
            $actor === 'bot' || str_starts_with($actor, 'bot:') => '🤖 бот',
            str_starts_with($actor, 'console:') => '⚙️ ' . substr($actor, 8),
            str_starts_with($actor, 'sql:') => '🛠 SQL вручну (' . substr($actor, 4) . ')',
            $actor === 'system' => '⚙️ система',
            default => '👤 ' . $actor,
        };
    }

    /** The Telegram id behind a `bot:<id>` actor, so the page can name the person. */
    public function getBotTelegramId(): ?string
    {
        return str_starts_with($this->actor, 'bot:') ? substr($this->actor, 4) : null;
    }
}
