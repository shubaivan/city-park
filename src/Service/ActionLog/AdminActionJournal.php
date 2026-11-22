<?php

namespace App\Service\ActionLog;

use App\Entity\AdminActionLog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Writes one «Журнал дій» row — see App\Entity\AdminActionLog for what the rows are.
 *
 * **Never fatal, never in the way.** An action that fails to be journalled must still
 * happen: the accountant's upload or a resident's button press is the point, the row is
 * bookkeeping. The insert runs through DBAL (not the ORM: it must not flush somebody
 * else's half-built entities), inside a savepoint when a transaction is open, so a failed
 * insert — say, the table not migrated yet — cannot abort the caller's transaction on
 * PostgreSQL. Each row is also written as a line to var/log/admin.log, which survives
 * even if the database write does not.
 */
class AdminActionJournal
{
    /** @var list<array<string, mixed>> rows kept in memory when the database is switched off (tests) */
    private array $recorded = [];

    private bool $writeToDatabase = true;

    public function __construct(
        private Connection $connection,
        private ActorContext $actor,
        private LoggerInterface $adminLogger,
    ) {}

    /**
     * @param array{
     *     kind?: string, summary: string, actor?: string, at?: \DateTimeImmutable,
     *     route?: ?string, method?: ?string, path?: ?string, status?: ?int,
     *     target_type?: ?string, target_id?: ?int, account_id?: ?int, prev_account_id?: ?int,
     *     user_id?: ?int, changes?: ?array<string, mixed>, ip?: ?string, user_agent?: ?string,
     *     request_id?: ?string
     * } $row
     */
    public function record(array $row): void
    {
        try {
            $data = [
                'at' => $row['at'] ?? new \DateTimeImmutable(),
                'kind' => $row['kind'] ?? AdminActionLog::KIND_CHANGE,
                'actor' => mb_substr($row['actor'] ?? $this->actor->actor(), 0, 80),
                'ip' => array_key_exists('ip', $row) ? $row['ip'] : $this->actor->ip(),
                'user_agent' => array_key_exists('user_agent', $row) ? $row['user_agent'] : $this->actor->userAgent(),
                'route' => self::cut($row['route'] ?? null, 80),
                'method' => self::cut($row['method'] ?? null, 8),
                'path' => self::cut($row['path'] ?? null, 255),
                'target_type' => self::cut($row['target_type'] ?? null, 32),
                'target_id' => $row['target_id'] ?? null,
                'account_id' => $row['account_id'] ?? null,
                'prev_account_id' => $row['prev_account_id'] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'summary' => mb_substr($row['summary'], 0, 2000),
                'changes' => $row['changes'] ?? null,
                'status' => $row['status'] ?? null,
                'request_id' => array_key_exists('request_id', $row) ? $row['request_id'] : $this->actor->requestId(),
            ];

            $this->adminLogger->info('action: ' . $data['summary'], [
                'actor' => $data['actor'],
                'kind' => $data['kind'],
                'target' => $data['target_type'] !== null ? $data['target_type'] . '#' . $data['target_id'] : null,
                'route' => $data['route'],
                'status' => $data['status'],
                'changes' => $data['changes'],
                'request_id' => $data['request_id'],
            ]);

            if (!$this->writeToDatabase) {
                $this->recorded[] = $data;

                return;
            }

            $this->insert($data);
        } catch (\Throwable $e) {
            $this->adminLogger->error('action journal row not written', [
                'summary' => $row['summary'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /** Tests switch the database off and read rows back from memory. */
    public function useMemory(): void
    {
        $this->writeToDatabase = false;
        $this->recorded = [];
    }

    /** @param array<string, mixed> $data */
    private function insert(array $data): void
    {
        $types = [
            'at' => Types::DATETIME_IMMUTABLE,
            'changes' => Types::JSON,
        ];

        if (!$this->connection->isTransactionActive()) {
            $this->connection->insert('admin_action_log', $data, $types);

            return;
        }

        // The caller's transaction already failed: a savepoint cannot even be opened, and
        // trying would leave DBAL's nesting level off by one. The admin.log line stands.
        if ($this->connection->isRollbackOnly()) {
            return;
        }

        // Nested: a savepoint (use_savepoints: true). A failure rolls back to it and leaves
        // the caller's transaction usable.
        $this->connection->beginTransaction();

        try {
            $this->connection->insert('admin_action_log', $data, $types);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
