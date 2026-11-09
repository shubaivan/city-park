<?php

namespace App\Telegram\Start\Command;

use App\Service\DeepLink;
use App\Service\TelegramUserService;
use App\Telegram\CarPlate\Command\CarPlateCommand;
use App\Telegram\Complaint\Command\ComplaintMenuCommand;
use App\Telegram\Debt\Command\DebtBoardCommand;
use App\Telegram\Voting\Command\VotingMenuCommand;
use App\Telegram\Guard\Command\GuardQrCommand;
use App\Telegram\Guard\Command\GuardScanCommand;
use App\Telegram\GuestPass\Command\GuestPassCommand;
use App\Telegram\GuestPass\Command\GuestPassScanCommand;
use App\Telegram\Info\Command\InfoCommand;
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
 *   g-<account>-<signature>   a resident's own QR    → GuardScanCommand
 *   p-<pass>-<signature>      a flat's guest pass    → GuestPassScanCommand
 *   s-<id>                    a service advert       → ServiceMenuCommand
 *   r-<id>                    a rental listing       → RentalMenuCommand
 *   c-<id>                    a complaint            → ComplaintMenuCommand
 *   d-<snapshot>              the debtors' board     → DebtBoardCommand
 *   v-<campaign>              a vote of the house    → VotingMenuCommand
 *   i-<topic>                 one FAQ topic          → InfoCommand
 *   a-<account>               «🚗 Мої авто»          → CarPlateCommand (the reader's own)
 *   qr                        «🪪 Мій QR-код»        → GuardQrCommand (the reader's own code)
 *   pass                      «👷 Пропуски»          → GuestPassCommand (the reader's own list)
 *
 * **An unknown payload opens the main menu**, rather than erroring or refusing. A link may
 * be forwarded months later, from a post that has since been deleted, or simply mistyped;
 * the person tapping it wanted the bot, and the bot is what they get.
 *
 * Every arrival but the two QR kinds is recorded — see LinkClick for why that table exists and
 * where its boundary is.
 */
class StartPayloadCommand
{
    /**
     * Direct openers for the two things a resident shows at the gate.
     *
     * Since 10.11.2026 a car enters only on a QR (правління ОСББ, 09.11.2026), and the
     * announcement links straight to the code and to the passes rather than to their
     * instructions. Exact words, not prefixes: `p-` is a guest pass being scanned and must
     * stay one. Not recorded as clicks, like the other kinds that open the reader's own
     * things rather than somebody's post.
     */
    public const OPEN_QR = 'qr';
    public const OPEN_PASSES = 'pass';

    public function __construct(
        private GuardScanCommand $guardScan,
        private GuestPassScanCommand $passScan,
        private ServiceMenuCommand $services,
        private RentalMenuCommand $rentals,
        private ComplaintMenuCommand $complaints,
        private DebtBoardCommand $debts,
        private VotingMenuCommand $votes,
        private InfoCommand $info,
        private CarPlateCommand $cars,
        private DeepLink $links,
        private TelegramUserService $telegramUserService,
        private GuardQrCommand $ownQr,
        private GuestPassCommand $passMenu,
    ) {}

    public function __invoke(Nutgram $bot, string $payload = ''): void
    {
        $payload = trim($payload);

        // An unconfirmed reader gets each handler's own «/phone» prompt.
        if ($payload === self::OPEN_QR) {
            ($this->ownQr)($bot);

            return;
        }

        if ($payload === self::OPEN_PASSES) {
            ($this->passMenu)($bot);

            return;
        }

        $kind = DeepLink::kindOf($payload);

        if ($kind === null) {
            StartCommand::send($bot);

            return;
        }

        if ($kind === DeepLink::KIND_GUARD) {
            ($this->guardScan)($bot, $payload);

            return;
        }

        // Both QR kinds carry a signature rather than a bare id, so neither goes through
        // idOf() or the click log below.
        if ($kind === DeepLink::KIND_PASS) {
            ($this->passScan)($bot, $payload);

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
            DeepLink::KIND_VOTE => $this->votes->openFromDeepLink($bot, $id),
            DeepLink::KIND_INFO => $this->info->openFromDeepLink($bot, $id),
            // The id is whose post it was; the list opened is always the reader's.
            DeepLink::KIND_CARS => ($this->cars)($bot),
            default => StartCommand::send($bot),
        };
    }
}
