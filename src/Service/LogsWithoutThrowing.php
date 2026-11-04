<?php

namespace App\Service;

/**
 * A log line must never decide what happens to money. Monolog throws on a full disk or an
 * unwritable file, and on these paths that came between TurboSMS accepting and the row being
 * saved, so a re-run billed twice. If the line cannot be written, the line is lost and nothing else.
 */
trait LogsWithoutThrowing
{
    /** @param 'info'|'warning'|'error' $level */
    private function logLine(string $level, string $message, array $context = []): void
    {
        try {
            $this->smsLogger->{$level}($message, $context);
        } catch (\Throwable) {
        }
    }
}
