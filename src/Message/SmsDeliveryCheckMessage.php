<?php

namespace App\Message;

/**
 * Ask TurboSMS once what became of one sent SMS. Comes back by itself, later, if the answer is
 * not final (SmsDeliveryChecker). Only reads, so unlike DebtSmsMessage it may be retried.
 */
final class SmsDeliveryCheckMessage
{
    public function __construct(
        public readonly int $logId,
        public readonly int $attempt,
        /** Unix time after which nobody waits for an answer any more. */
        public readonly int $deadline,
    ) {}
}
