<?php

namespace App\Message;

/**
 * One debt SMS to one number, as confirmed in the panel.
 *
 * What was confirmed is a wish made at the time: DebtSmsDelivery looks at the flat again at
 * delivery. The text here only names the row of a job that did not get as far as writing one.
 */
final class DebtSmsMessage
{
    public function __construct(
        public readonly int $runId,
        public readonly int $accountId,
        public readonly string $phone,
        public readonly string $text,
    ) {}
}
