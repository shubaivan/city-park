<?php

namespace App\Telegram\Start\Command;

use App\Service\OsbbContacts;
use App\Entity\Account;
use App\Service\ComplaintService;
use App\Service\PropertyRegistry;
use App\Service\DebtBoardService;
use App\Service\GuardService;
use App\Service\SchedulePavilionService;
use App\Service\ResidentChatService;
use App\Service\TelegramUserService;
use App\Repository\ComplaintRepository;
use App\Repository\ServiceOfferRepository;
use App\Telegram\Complaint\Command\ComplaintMenuCommand;
use App\Telegram\ServiceOffer\Command\ServiceMenuCommand;
use App\Telegram\Guard\Command\GuardCommand;
use App\Telegram\Guard\Command\GuardQrCommand;
use App\Telegram\Debt\Command\DebtBoardCommand;
use App\Telegram\ResidentChat\Command\ResidentChatCommand;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'Початок спілкування';

    public const MAIN_MENU_CALLBACK = 'main-menu';

    public function handle(Nutgram $bot): void
    {
        self::send($bot, edit: false);
    }

    public function __invoke(Nutgram $bot): mixed
    {
        $edit = $bot->isCallbackQuery();
        self::send($bot, edit: $edit);
        return null;
    }

    public static function send(Nutgram $bot, bool $edit = false): void
    {
        // Resolved once and passed down: the header, the debtors' board and the menu
        // all need it, and each lookup is a DB round-trip on every menu render.
        $account = self::currentAccount($bot);

        $text = self::header($bot, $account) . self::chairBlock($account) . self::debtBlock($bot, $account) . 'Оберіть:';
        $markup = self::mainMenuMarkup($bot, $account);

        if ($edit) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
                return;
            } catch (\Throwable) {
                // fall through to a new message
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    /**
     * Address + особовий рахунок block shown above the menu.
     *
     * Residents kept confusing "рахунок" with a bank account (pasting IBANs when asked
     * for it), so the number is spelled out on every menu render — tap-to-copy via
     * <code>, and explicitly labelled as not-a-bank-account.
     *
     * Renders nothing when the account can't be resolved (unconfirmed phone, or a
     * family member not yet linked) so the menu never breaks.
     */
    private static function header(Nutgram $bot, ?Account $account): string
    {
        return self::renderHeader(self::objects($bot, $account), self::debtFiguresArePublishable($bot));
    }

    /**
     * **Every object the household owns, not just the one the person is linked to.**
     *
     * `TelegramUser.account_id` points at exactly one Account, but a person may own a
     * flat, a parking space and a комірчина — three особові рахунки, because that is how
     * the ОСББ bills them, tied together by `owner_group_id`. Until this listed them all,
     * the bot printed one number and the other objects existed nowhere in it: their
     * особовий рахунок is what the accountant asks for on the phone, and their owner had
     * no way to read it back.
     *
     * The one-object wording is kept word for word — 172 of the 173 objects on prod are
     * somebody's only one, and the singular sentence is what residents have been reading
     * since the number was first put on the menu.
     *
     * Every label goes through `getStreetPlaceLabel()`, so a комірчина is named
     * «комірчина 168» and not «кв. 168». The header used to build that string itself with
     * a hardcoded "кв. %s", which was right for a flat and wrong for everything else —
     * the same mistake the debtors' board was corrected for on 03.09.2026.
     *
     * @param Account[] $objects
     */
    public static function renderHeader(array $objects, bool $withDebt = false): string
    {
        $objects = array_values(array_filter(
            $objects,
            static fn (Account $account): bool => (string)$account->getAccountNumber() !== '',
        ));

        if ($objects === []) {
            return '';
        }

        if (count($objects) === 1) {
            return sprintf(
                "🏠 <b>%s</b>\n"
                . "🧾 Ваш особовий рахунок: <code>%s</code>\n"
                . "<i>Це ваш номер в ОСББ (не банківський) — називайте його, коли звертаєтесь до бухгалтера.</i>\n\n",
                self::esc($objects[0]->getStreetPlaceLabel()),
                self::esc((string)$objects[0]->getAccountNumber()),
            );
        }

        $lines = ['🧾 <b>Ваші об’єкти в ОСББ:</b>'];

        foreach ($objects as $object) {
            $lines[] = sprintf(
                '%s %s — <code>%s</code>%s',
                $object->getUnitTypeIcon(),
                self::esc($object->getStreetPlaceLabel()),
                self::esc((string)$object->getAccountNumber()),
                $withDebt ? self::debtSuffix($object) : '',
            );
        }

        $lines[] = '<i>Це ваші номери в ОСББ (не банківські) — називайте той, про який питаєте бухгалтера.</i>';

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * What this particular object owes, beside its own особовий рахунок.
     *
     * Each object is billed separately and has its own threshold, and a debt on **any** of
     * them blocks booking for the whole household — so "which of my objects owes" is a
     * real question the resident could not answer anywhere in the bot. The board below
     * names only objects that reach the published list; this says it per рахунок.
     *
     * «без боргу» is spelled out rather than left blank: on a list of two lines, a missing
     * annotation reads as missing data, not as zero.
     *
     * Rendered only when the caller says the figures are fresh (`DebtBoardService::isAvailable()`).
     * The «станом на» date lives one block below in the same message, and a sum published
     * without one is the thing the board's staleness rule exists to prevent.
     */
    private static function debtSuffix(Account $account): string
    {
        $debt = (float)($account->getDebt() ?? 0);

        // Signed, because the bare number reads as anything — a charge, a payment, a
        // tariff. A minus beside the рахунок says which direction it goes without a word
        // of explanation, and it is the shape a receipt uses.
        return $debt >= 1
            ? sprintf(' · 💸 <b>−%s грн</b>', number_format($debt, 0, '.', ' '))
            : ' · ✅ без боргу';
    }

    /**
     * Wrapped in a catch-all like every other block on this menu: the owner group is one
     * more DB round-trip, and nothing on the header may stop /start from rendering.
     *
     * @return Account[]
     */
    /**
     * Whether the debt figures are fresh enough to print. Same answer the debtors' board
     * gives itself, so the header cannot show a sum on a day the board has gone quiet.
     */
    private static function debtFiguresArePublishable(Nutgram $bot): bool
    {
        try {
            $board = $bot->getContainer()->get(DebtBoardService::class);

            return $board instanceof DebtBoardService && $board->isAvailable();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function objects(Nutgram $bot, ?Account $account): array
    {
        if (!$account instanceof Account) {
            return [];
        }

        try {
            $registry = $bot->getContainer()->get(PropertyRegistry::class);

            if ($registry instanceof PropertyRegistry) {
                return $registry->objectsOfAccount($account);
            }
        } catch (\Throwable) {
            // fall through to the account we already have
        }

        return [$account];
    }

    /**
     * Dependencies are pulled from the bot container instead of the constructor:
     * Nutgram's registerCommand() instantiates Command subclasses with a plain
     * `new $class()` (MessageListeners::registerCommand), so a required constructor
     * argument would blow up at bot boot for every update. Method injection is out
     * too — Handler::__invoke(Nutgram $bot) fixes the signature. TelegramUserService
     * is therefore declared public in config/services.yaml so the delegated Symfony
     * container hands back the same shared instance RequestSubscriber init'd.
     */
    /**
     * Who runs the ОСББ, and how to reach them.
     *
     * Residents kept asking the bot things only a person can answer, so the people are
     * named on the menu rather than buried in the FAQ. Neither has a Telegram @username —
     * checked against the registry, both fields are empty — so the links are the
     * phone-number form, which opens a Telegram chat without one, and the numbers
     * themselves are in <code> for tap-to-copy in case they would rather ring.
     *
     * The accountant is named alongside the chair because they answer different questions
     * and residents cannot be expected to know which: Людмила decides, Аліна holds the
     * registry, and «немає номера в базі» is hers. Her number was already in the block and
     * unblock messages before this — but only somebody already blocked ever saw it.
     *
     * Shown only to a linked resident: an unlinked visitor browsing 🔑 Оренда is not owed
     * the officers' phones.
     */
    private static function chairBlock(?Account $account): string
    {
        if (!$account instanceof Account) {
            return '';
        }

        return OsbbContacts::chair() . "\n"
            . OsbbContacts::accountant() . "\n"
            . "<i>Особові рахунки, нарахування, борги, прив'язка квартири — до бухгалтера.</i>\n"
            . OsbbContacts::repairs() . "\n"
            . "<i>Що зламалось у будинку і що з цим робиться — до нього, або через «🔧 Заявки».</i>\n"
            . OsbbContacts::developer() . "\n"
            . "<i>Не працює кнопка, дивна відповідь бота, помилка в даних — до нього.</i>\n\n";
    }

    /**
     * The house's total debt and the three largest debtors, above the menu.
     *
     * Asked for by the head of the ОСББ as a nudge towards paying: the total is there so
     * every resident knows what the house is short of, and the top three are named by
     * apartment — no names, no phone numbers. Verified residents only, and the service
     * itself falls silent when the figures are too old to stand behind.
     *
     * Wrapped in a catch-all for the same reason header() is: a debtors' board is a
     * decoration on the menu, and no decoration may ever stop /start from rendering.
     */
    private static function debtBlock(Nutgram $bot, ?Account $account): string
    {
        if (!$account instanceof Account) {
            return '';
        }

        try {
            $board = $bot->getContainer()->get(DebtBoardService::class);

            if (!$board instanceof DebtBoardService) {
                return '';
            }

            return $board->menuBlock($account);
        } catch (\Throwable) {
            return '';
        }
    }

    private static function currentAccount(Nutgram $bot): ?Account
    {
        try {
            $telegramUserService = $bot->getContainer()->get(TelegramUserService::class);

            if (!$telegramUserService instanceof TelegramUserService) {
                return null;
            }

            // getCurrentUser() reads an uninitialised typed property when
            // RequestSubscriber never ran (CLI / non-webhook contexts) — that is an
            // Error, not a null, so it has to be caught rather than checked.
            $user = $telegramUserService->getCurrentUser();
            if (!$user) {
                return null;
            }

            return $telegramUserService->resolveAccount($user);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function homeButton(): InlineKeyboardButton
    {
        return InlineKeyboardButton::make('🏠 На головну', callback_data: self::MAIN_MENU_CALLBACK);
    }

    private static function mainMenuMarkup(Nutgram $bot, ?Account $account = null): InlineKeyboardMarkup
    {
        // The gate's own button, above everything: he opens the bot for exactly one
        // reason, and he does it standing outside in the dark. Nobody else sees it —
        // isGuard() is a short list of Telegram ids in .env.local.
        //
        // **Added to the menu, never replacing it.** The first shape returned a
        // one-button menu to anybody in that list, which is wrong twice over: it takes
        // a resident-guard's own flat, bookings and debts away the moment his id is
        // added, and it would have taken «🔧 Заявки» from Сергій, who has no особовий
        // рахунок and manages the register. What each person may use is decided by the
        // rows below, every one of them already gated on what they actually have.
        $markup = InlineKeyboardMarkup::make();

        if (self::isGuard($bot)) {
            $markup->addRow(InlineKeyboardButton::make(
                '🛡 Хто зараз в альтанці',
                callback_data: GuardCommand::MENU_CALLBACK,
            ));
        }

        $markup
            // Оренда sits first on purpose: it is the newest section and residents were
            // not finding it at the bottom of the menu, under three rows they already
            // know by heart. Booking is the everyday action and stays one tap away.
            ->addRow(
                InlineKeyboardButton::make('🔑 Оренда та продаж', callback_data: 'rental-menu'),
            );

        // Directly under Оренда, and only for a confirmed resident: every offer names the
        // flat its author lives in, which is most of why a neighbour trusts it and exactly
        // why an unlinked visitor is not shown the list. The count rides on the label for
        // the same reason it does on «Заявки» — an empty board and a board with eleven
        // tradespeople on it are different invitations, and the button is the only place
        // that difference can be read before tapping.
        if ($account instanceof Account) {
            $markup->addRow(InlineKeyboardButton::make(
                self::servicesLabel($bot),
                callback_data: ServiceMenuCommand::MENU_CALLBACK,
            ));
        }

        // Second, above the everyday buttons, and only once the chat exists: a button
        // leading nowhere is worse than no button, and the group is made by hand in
        // Telegram, not by a migration. The position matches the slash menu, because the
        // announcement to residents tells them where to look — "друга кнопка" has to be
        // true in both places.
        if (self::residentChatOpen($bot)) {
            $markup->addRow(
                InlineKeyboardButton::make(
                    '🏘 Чат мешканців',
                    callback_data: ResidentChatCommand::MENU_CALLBACK,
                ),
            );
        }

        // Third, with the open count on the label: the number is the whole point — it says
        // at a glance whether the thing you came to report is already known. Linked
        // residents only, like the register itself.
        if ($account instanceof Account) {
            $markup->addRow(
                InlineKeyboardButton::make(
                    self::complaintsLabel($bot),
                    callback_data: ComplaintMenuCommand::MENU_CALLBACK,
                ),
            );
        }

        // The same board the guard reads, without the flat numbers: a resident opens it
        // asking «вільно чи ні», which the hours answer on their own. It sits directly
        // above «Бронювання» because that is the sequence — look, then book — and it is
        // hidden from a guard, who already has his own copy of it at the top of the menu
        // under a name that says what his version is for.
        if ($account instanceof Account && !self::isGuard($bot)) {
            $markup->addRow(InlineKeyboardButton::make(
                '🏛 Альтанки зараз',
                callback_data: GuardCommand::MENU_CALLBACK,
            ));
        }

        $markup
            ->addRow(
                InlineKeyboardButton::make('Бронювання', callback_data: 'schedule-pavilion'),
                InlineKeyboardButton::make('Переглянути свої', callback_data: 'own-schedule'),
                InlineKeyboardButton::make('Як доїхати?', callback_data: 'type:route'),
            )
            ->addRow(
                InlineKeyboardButton::make('📜 Історія бронювань', callback_data: 'booking-history'),
                InlineKeyboardButton::make('📸 Завантажити фото', callback_data: 'photo-upload-info'),
            )
            ->addRow(
                InlineKeyboardButton::make('ℹ️ Інструкція та FAQ', callback_data: 'info-menu'),
                InlineKeyboardButton::make('🗳️ Голосування', callback_data: 'voting-menu'),
            );

        // The QR for the gate, and only while a booking is actually running: it is on the
        // menu exactly when you are sitting in the альтанка and gone the rest of the
        // month, which is also why it needs no explaining. One indexed query per render.
        if ($account instanceof Account && self::hasRunningBooking($bot, $account)) {
            $markup->addRow(
                InlineKeyboardButton::make(
                    '🔒 QR для охорони',
                    callback_data: GuardQrCommand::MENU_CALLBACK,
                ),
            );
        }

        // Last row, and only for a verified resident: the full debtors' list is
        // house-internal, and somebody who opened the bot to browse 🔑 Оренда is not
        // part of the house. Shown even when the board above is hidden as stale — the
        // report then explains the silence instead of leaving a dead button.
        if ($account instanceof Account) {
            $markup->addRow(
                InlineKeyboardButton::make(
                    '💸 Звіт боржників',
                    callback_data: DebtBoardCommand::MENU_CALLBACK,
                ),
            );
        }

        return $markup;
    }

    /**
     * «🛠 Послуги (7)» — how many neighbours currently offer something.
     *
     * Resolved through the container at render time like the other menu counts, which is
     * why ServiceOfferRepository must stay `public: true` in services.yaml: a private
     * service is inlined at compile time and the lookup throws straight into the catch
     * below. Right for a decoration, and exactly why it fails silently — both guard
     * buttons shipped that way on 07.09.2026 and simply did not appear.
     */
    private static function servicesLabel(Nutgram $bot): string
    {
        try {
            $repo = $bot->getContainer()->get(ServiceOfferRepository::class);

            if ($repo instanceof ServiceOfferRepository) {
                $open = $repo->countActive(new \DateTime());

                return $open > 0 ? sprintf('🛠 Послуги (%d)', $open) : '🛠 Послуги';
            }
        } catch (\Throwable) {
            // A count is decoration; the button must appear either way.
        }

        return '🛠 Послуги';
    }

    private static function complaintsLabel(Nutgram $bot): string
    {
        try {
            $repo = $bot->getContainer()->get(ComplaintRepository::class);

            if ($repo instanceof ComplaintRepository) {
                $open = $repo->countOpen();

                return $open > 0 ? sprintf('🔧 Заявки (%d)', $open) : '🔧 Заявки';
            }
        } catch (\Throwable) {
            // A count is decoration; the button must appear either way.
        }

        return '🔧 Заявки';
    }

    /**
     * The guard is staff, not a resident: he has no особовий рахунок and the ordinary menu
     * would offer him booking, debts and a chat he is not part of. Resolved through the
     * container like the other two menu checks, and failing closed — a container that
     * cannot answer must not hand somebody the flat-by-flat board.
     */
    private static function isGuard(Nutgram $bot): bool
    {
        try {
            $guard = $bot->getContainer()->get(GuardService::class);
            $users = $bot->getContainer()->get(TelegramUserService::class);

            return $guard instanceof GuardService
                && $users instanceof TelegramUserService
                && $guard->isGuard($users->getCurrentUser());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Is this household in the альтанка right now? Decides whether «🔒 QR для охорони» is
     * on the menu. Fails closed, like every other container lookup here.
     */
    private static function hasRunningBooking(Nutgram $bot, Account $account): bool
    {
        try {
            $guard = $bot->getContainer()->get(GuardService::class);

            return $guard instanceof GuardService
                && $guard->runningSessionFor($account, SchedulePavilionService::createNewDate()) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function residentChatOpen(Nutgram $bot): bool
    {
        try {
            $service = $bot->getContainer()->get(ResidentChatService::class);

            return $service instanceof ResidentChatService && $service->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }
}
