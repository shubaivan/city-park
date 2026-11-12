<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * `panel_login_token`, written and burned with plain SQL. The burn is one conditional
 * UPDATE — `used_at IS NULL AND expires_at > now` — so single use holds even for two
 * requests arriving together; a read-then-write would let both through.
 *
 * Times are Kyiv wall clock without an offset, like every other stored moment here.
 */
class DoctrinePanelLoginTokenStore implements PanelLoginTokenStore
{
    public function __construct(private Connection $db) {}

    public function save(string $tokenHash, string $telegramId, \DateTimeInterface $expiresAt): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Kyiv'));

        // Expired rows are worth nothing; a guard signs in a few times a day, so this is
        // the whole retention policy.
        $this->db->executeStatement(
            'DELETE FROM panel_login_token WHERE expires_at < :cutoff',
            ['cutoff' => $now->modify('-1 day')->format('Y-m-d H:i:s')],
        );

        $this->db->insert('panel_login_token', [
            'token_hash' => $tokenHash,
            'telegram_id' => $telegramId,
            'expires_at' => self::kyiv($expiresAt),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function consume(string $tokenHash, \DateTimeInterface $now): ?string
    {
        $burned = $this->db->executeStatement(
            'UPDATE panel_login_token SET used_at = :now
              WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now',
            ['now' => self::kyiv($now), 'hash' => $tokenHash],
        );

        if ($burned !== 1) {
            return null;
        }

        $id = $this->db->fetchOne(
            'SELECT telegram_id FROM panel_login_token WHERE token_hash = :hash',
            ['hash' => $tokenHash],
        );

        return $id === false ? null : (string)$id;
    }

    private static function kyiv(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)
            ->setTimezone(new \DateTimeZone('Europe/Kyiv'))
            ->format('Y-m-d H:i:s');
    }
}
