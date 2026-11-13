<?php

namespace App\Service;

use App\Entity\GateEvent;
use App\Entity\QrScan;

/**
 * What came of one press of «🚗 В'їзд» / «🚙 Виїзд». Read by GateEventCommand, which turns
 * each outcome into what the guard is shown.
 */
final class GatePress
{
    /** Written now. */
    public const RECORDED = 'recorded';

    /** This scan already has its event — a double tap, or Telegram re-delivering the tap. */
    public const ALREADY = 'already';

    /** Older than GateLogService::TTL_MINUTES: scan the code again. */
    public const EXPIRED = 'expired';

    /** Not this person's button — somebody else's scan, or no longer a guard. */
    public const FORBIDDEN = 'forbidden';

    /** Malformed, a bad signature, or a scan that is gone / was not a valid one. */
    public const INVALID = 'invalid';

    public function __construct(
        public readonly string $status,
        public readonly ?GateEvent $event = null,
        public readonly ?QrScan $scan = null,
    ) {}
}
