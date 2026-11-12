<?php

namespace App\Service;

/**
 * Where the guard's one-time panel links are kept: the SHA-256 of the token, whose it is,
 * until when. An interface so the rules (single use, expiry) are testable without a
 * database; the real one is {@see DoctrinePanelLoginTokenStore}.
 */
interface PanelLoginTokenStore
{
    public function save(string $tokenHash, string $telegramId, \DateTimeInterface $expiresAt): void;

    /**
     * Burns the token and says whose it was — in one step, so two taps on the same link
     * cannot both get in. Null: unknown, already used or expired.
     */
    public function consume(string $tokenHash, \DateTimeInterface $now): ?string;
}
