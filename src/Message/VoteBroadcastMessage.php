<?php

namespace App\Message;

/**
 * Async job: deliver the "vote opened" notice to one voter account. One of these is
 * dispatched per eligible voter when a campaign opens, so the admin request doesn't block
 * on hundreds of sequential Telegram sends. Routed to the `async` (Doctrine) transport and
 * processed by the city-park-messenger worker.
 */
final class VoteBroadcastMessage
{
    public function __construct(
        public readonly int $campaignId,
        public readonly int $recipientAccountId,
    ) {}
}
