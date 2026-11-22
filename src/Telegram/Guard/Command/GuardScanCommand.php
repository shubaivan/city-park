<?php

namespace App\Telegram\Guard\Command;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\TelegramUserRepository;
use App\Entity\QrScan;
use App\Service\GateLogService;
use App\Service\GuardService;
use App\Service\GuestPassService;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * The other end of the QR: `/start g-<account>-<signature>`, arriving because a guard
 * pointed a camera at a resident's phone.
 *
 * Registered as `start {payload}`, which Nutgram anchors — a bare `/start` still reaches
 * StartCommand, and this only ever sees a deep link.
 *
 * **Any confirmed resident may scan, not only the guard** (Иван, 09.09.2026: «я хотел бы
 * чтоб кто угодно из ЖК мог проверить кого угодно, а не только охрана»). There are two
 * guards and 457 residents, and «ці люди тут по броні?» is asked by whoever happens to be
 * standing in the yard. An unlinked visitor still gets nothing — the same line the debtors'
 * board and the complaints register draw.
 *
 * **One answer, the same for everybody who may read it.** The guard's version and the
 * neighbour's were briefly different — the flat only for him — and that was one rule too
 * many: the code is shown deliberately, by the person it belongs to, to somebody they are
 * standing in front of, and «це мешканець» without saying *which* flat answers nothing a
 * neighbour could not already see. Иван's call, the same evening. So there is one text,
 * and no branch that could ever leak the wrong half to the wrong reader.
 *
 * Answers:
 *
 * - **unlinked** → «код читають підтверджені мешканці», and nothing about the holder;
 * - **anybody else** → ✅ the flat, plus the booking if one is running, or a plain «броні
 *   на зараз немає» — the answer that matters either way, because a screenshot from last
 *   Saturday must read as wrong rather than as a bot error.
 *
 * A bad signature is treated as a stranger, not as an error: `readToken()` returns null and
 * the payload never reaches the database.
 */
class GuardScanCommand
{
    public function __construct(
        private GuardService $guard,
        private TelegramUserService $telegramUserService,
        private AccountRepository $accounts,
        private GuestPassService $passes,
        private ?LoggerInterface $chatLogger = null,
        private ?GateLogService $gate = null,
        private ?TelegramUserRepository $people = null,
    ) {}

    public function __invoke(Nutgram $bot, string $payload = ''): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $viewerAccount = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$this->guard->mayScan($user, $viewerAccount)) {
            // An unlinked visitor, or a forwarded screenshot opened by somebody outside the
            // house: told what the code is and how to become someone who can read it, never
            // anything about whose code it is.
            $bot->sendMessage(
                text: "🔒 <b>QR-код мешканця ЖК</b>\n\n"
                    . "Його читають підтверджені мешканці — щоб перевірити, чи людина "
                    . "справді з нашого ЖК і чи є в неї бронь на альтанку.\n\n"
                    . 'Ви ще не підтверджені: натисніть /phone і поділіться номером телефону.',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            return;
        }

        $token = $this->guard->readResidentToken(trim($payload));
        $accountId = $token['account'] ?? null;
        $personId = $token['person'] ?? null;
        $account = $accountId !== null ? $this->accounts->find($accountId) : null;
        $person = $personId !== null && $account instanceof Account ? $this->people?->find($personId) : null;

        $this->chatLogger?->info('guard qr scanned', [
            'telegram_id' => $user?->getTelegramId(),
            'payload_valid' => $accountId !== null,
            'account_id' => $accountId,
            'person_id' => $personId,
        ]);

        $isGuard = $this->guard->isGuard($user);

        if (!$account instanceof Account) {
            $this->passes->recordScan(QrScan::KIND_RESIDENT, QrScan::RESULT_UNKNOWN, $user, null, null, $isGuard);
            $this->answer($bot, "⚠️ <b>Код не розпізнано</b>\n\nПопросіть відкрити його в боті ще раз.");

            return;
        }

        // A personal code (22.11.2026) is only as good as the link behind it: somebody
        // unlinked from the flat, moved to another one or gone from the bot holds a dead
        // picture. Said plainly, never as a bot error, and with nothing about who they were.
        if ($personId !== null && !GuardService::personStillOnFlat($person, (int)$account->getId())) {
            $this->passes->recordScan(QrScan::KIND_RESIDENT, QrScan::RESULT_PERSON_GONE, $user, $account, null, $isGuard, $person);
            // Why it is dead is the guard's business only; a neighbour is not told that
            // somebody left that flat.
            $this->answer($bot, $isGuard
                ? "⛔ <b>Код більше не дійсний</b>\n\n"
                    . "Ця людина вже не прив'язана до квартири в боті. "
                    . 'Якщо вона тут живе — хай відкриє «🪪 Мій QR-код» у боті ще раз.'
                : "⛔ <b>Код не дійсний</b>\n\nПопросіть відкрити його в боті ще раз.");

            return;
        }

        $now = SchedulePavilionService::createNewDate();
        $session = $this->guard->runningSessionFor($account, $now);
        $result = $session === null ? QrScan::RESULT_NO_BOOKING : QrScan::RESULT_OK;
        $scan = $this->passes->recordScan(QrScan::KIND_RESIDENT, $result, $user, $account, null, $isGuard, $person);

        // Only the gate gets who it is and who to ring; a neighbour checking a code keeps
        // the address, exactly as before personal codes.
        $contact = $isGuard ? self::guardContact($account, $person) : '';

        if ($session === null) {
            $this->answer($bot, sprintf(
                "✅ <b>Код дійсний</b>\n\nЦе мешканець нашого ЖК\n<b>%s</b>\n\n"
                    . '<i>Броні на альтанку зараз немає.</i>%s',
                htmlspecialchars($account->getPlaceLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $contact,
            ), $this->gate?->buttonsFor($scan, $user));

            return;
        }

        $this->answer($bot, sprintf(
            "✅ <b>Код дійсний</b>\n\nЦе мешканець нашого ЖК\n<b>%s</b>\n"
                . "🏛 Зараз бронь: %s альтанка · <b>%s–%s</b>\n\n<i>Перевірено %s.</i>%s",
            htmlspecialchars(GuardService::place($session), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            SchedulePavilionService::pavilionName($session['pavilion']),
            $session['start']->format('H:i'),
            $session['end']->format('H:i'),
            $now->format('d.m о H:i'),
            $contact,
        ), $this->gate?->buttonsFor($scan, $user));
    }

    /**
     * The guard's extra lines. A personal code names its holder first — name, role, phone —
     * and then the rest of the flat to ring; an old flat-level code says the person is not
     * known and lists the flat as before.
     */
    public static function guardContact(Account $account, ?TelegramUser $person): string
    {
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($person === null) {
            return "\n\n👤 <i>Хто саме з квартири — невідомо (старий код квартири).</i>"
                . GuardService::contactBlock($account->getUsers());
        }

        $others = [];

        foreach ($account->getUsers() as $member) {
            if ($member !== $person && ($member->getId() === null || $member->getId() !== $person->getId())) {
                $others[] = $member;
            }
        }

        $head = sprintf(
            "\n\n<b>Чий код:</b> 👤 <b>%s</b> · %s · 📞 %s",
            $esc(GuardService::contactName($person)),
            $esc($person->getRoleLabel()),
            $esc(GuardService::contactPhone($person)),
        );

        return $others === [] ? $head . "\n<i>(бачить лише охорона)</i>" : $head . GuardService::contactBlock($others, 3, 'Інші з квартири');
    }

    /**
     * The button under the answer goes to the board, which renders itself per reader — the
     * guard's names flats, everybody else's says «зайнято». One label, because at this
     * point the bot has already answered and the two boards are the same section.
     *
     * The guard's valid scan also gets «🚗 В'їзд / 🚙 Виїзд» above it (GateLogService);
     * `$gate` is null for everybody else, so their answer is exactly what it was.
     *
     * @param InlineKeyboardButton[]|null $gate
     */
    private function answer(Nutgram $bot, string $text, ?array $gate = null): void
    {
        $markup = InlineKeyboardMarkup::make();

        if ($gate !== null) {
            $markup->addRow(...$gate);
        }

        $bot->sendMessage(
            text: $text,
            parse_mode: ParseMode::HTML,
            reply_markup: $markup->addRow(self::boardButton()),
        );
    }

    /** The button every resident-code answer ends with; GateEventCommand puts it back. */
    public static function boardButton(): InlineKeyboardButton
    {
        return InlineKeyboardButton::make('🏛 Альтанки зараз', callback_data: GuardCommand::MENU_CALLBACK);
    }
}
