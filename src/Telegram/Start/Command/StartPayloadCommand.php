<?php

namespace App\Telegram\Start\Command;

use App\Service\DeepLink;
use App\Service\TelegramUserService;
use App\Telegram\Complaint\Command\ComplaintMenuCommand;
use App\Telegram\Debt\Command\DebtBoardCommand;
use App\Telegram\Guard\Command\GuardScanCommand;
use App\Telegram\Rental\Command\RentalMenuCommand;
use App\Telegram\ServiceOffer\Command\ServiceMenuCommand;
use SergiX44\Nutgram\Nutgram;

/**
 * Everything that arrives as `/start <payload>` — a deep link somebody tapped or scanned.
 *
 * Registered as `start {payload}`, which Nutgram anchors, so a bare `/start` still reaches
 * StartCommand. Getting that wrong takes the main menu away from 457 people and shows up
 * as «бот не відповідає», not as an error anywhere; `GuardWiringTest` pins it.
 *
 * The prefixes live in {@see DeepLink::PREFIXES}, next to the code that *builds* the links,
 * so a new kind cannot be added on one side only:
 *
 *   g-<account>-<signature>   the guard's QR         → GuardScanCommand
 *   s-<id>                    a service advert       → ServiceMenuCommand
 *   r-<id>                    a rental listing       → RentalMenuCommand
 *   c-<id>                    a complaint            → ComplaintMenuCommand
 *   d-<snapshot>              the debtors' board     → DebtBoardCommand
 *
 * **An unknown payload opens the main menu**, rather than erroring or refusing. A link may
 * be forwarded months later, from a post that has since been deleted, or simply mistyped;
 * the person tapping it wanted the bot, and the bot is what they get.
 *
 * Every arrival but the guard's is recorded — see LinkClick for why that table exists and
 * where its boundary is.
 */
class StartPayloadCommand
{
    public function __construct(
        private GuardScanCommand $guardScan,
        private ServiceMenuCommand $services,
        private RentalMenuCommand $rentals,
        private ComplaintMenuCommand $complaints,
        private DebtBoardCommand $debts,
        private DeepLink $links,
        private TelegramUserService $telegramUserService,
    ) {}

    public function __invoke(Nutgram $bot, string $payload = ''): void
    {
        $payload = trim($payload);
        $kind = DeepLink::kindOf($payload);

        if ($kind === null) {
            StartCommand::send($bot);

            return;
        }

        if ($kind === DeepLink::KIND_GUARD) {
            ($this->guardScan)($bot, $payload);

            return;
        }

        $id = DeepLink::idOf($payload, $kind);

        // Recorded before the card is rendered, so a slow or failing render still counts
        // the tap — the click is a fact about the post either way. record() swallows its
        // own errors for the mirror-image reason: a measurement must never cost somebody
        // the page they asked for.
        $this->links->record($kind, $id, $this->telegramUserService->getCurrentUser());

        match ($kind) {
            DeepLink::KIND_SERVICE => $this->services->openFromDeepLink($bot, $id),
            DeepLink::KIND_RENTAL => $this->rentals->openFromDeepLink($bot, $id),
            DeepLink::KIND_COMPLAINT => $this->complaints->openFromDeepLink($bot, $id),
            DeepLink::KIND_DEBT => $this->debts->openFromDeepLink($bot, $id),
            default => StartCommand::send($bot),
        };
    }
}
