<?php

namespace App\Telegram\Start\Command;

use App\Telegram\Guard\Command\GuardScanCommand;
use App\Telegram\ServiceOffer\Command\ServiceMenuCommand;
use SergiX44\Nutgram\Nutgram;

/**
 * Everything that arrives as `/start <payload>` — a deep link somebody tapped or scanned.
 *
 * Registered as `start {payload}`, which Nutgram anchors, so a bare `/start` still reaches
 * StartCommand. Getting that wrong takes the main menu away from 457 people and shows up
 * as «бот не відповідає», not as an error anywhere; `GuardWiringTest` pins it.
 *
 * This exists because there is now more than one kind of link. The guard's QR was the
 * first, and it was wired straight to its handler — so the day a second one appeared, every
 * link that was not a QR would have been answered «цей QR-код зчитує охорона». One router,
 * and each prefix says what it is:
 *
 *   g-<account>-<signature>   the guard's QR              → GuardScanCommand
 *   s-<id>                    a service advert            → ServiceMenuCommand
 *
 * **An unknown payload opens the main menu**, rather than erroring or refusing. A link may
 * be forwarded months later, from a post that has since been deleted, or simply mistyped;
 * the person tapping it wanted the bot, and the bot is what they get.
 */
class StartPayloadCommand
{
    public const SERVICE_PREFIX = 's-';
    public const GUARD_PREFIX = 'g-';

    public function __construct(
        private GuardScanCommand $guardScan,
        private ServiceMenuCommand $services,
    ) {}

    public function __invoke(Nutgram $bot, string $payload = ''): void
    {
        $payload = trim($payload);

        if (str_starts_with($payload, self::SERVICE_PREFIX)) {
            $this->services->openFromDeepLink($bot, (int)substr($payload, strlen(self::SERVICE_PREFIX)));

            return;
        }

        if (str_starts_with($payload, self::GUARD_PREFIX)) {
            ($this->guardScan)($bot, $payload);

            return;
        }

        StartCommand::send($bot);
    }
}
