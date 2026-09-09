<?php

namespace App\Telegram\Start\Command;

use App\Service\OsbbContacts;
use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Service\BlockVoteService;
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
        return self::renderHeader(
            self::objects($bot, $account),
            self::debtFiguresArePublishable($bot) && self::owesForTheFlat($bot),
            self::household($bot, $account),
        );
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
    public static function renderHeader(
        array $objects,
        bool $withDebt = false,
        array $household = [],
    ): string {
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
                . "<i>Це ваш номер в ОСББ (не банківський) — називайте його, коли звертаєтесь до бухгалтера.</i>\n%s\n",
                self::esc($objects[0]->getStreetPlaceLabel()),
                self::esc((string)$objects[0]->getAccountNumber()),
                self::householdLine($household),
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

        $others = self::householdLine($household);

        if ($others !== '') {
            $lines[] = rtrim($others, "\n");
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * «👥 На цьому рахунку також: Марина (родич)».
     *
     * The bot is the only place a resident can check that the accountant's linking actually
     * happened. Vitalii asked on 08.09.2026 to have his wife added; she was added, and
     * nothing on his screen changed — so the next thing he does is ask again. One line
     * closes that loop, and it also lets somebody notice a name that should not be on their
     * flat, which is otherwise visible only to an admin.
     *
     * Names go through `TelegramUser::getDisplayName()` — the registry name when the ОСББ
     * knows it, the Telegram one otherwise. `full_name` is what the accountant typed off a
     * квитанція, while the other is whatever the person chose to call themselves, and
     * «Vitalii» is a worse answer to «хто ще на моєму рахунку» than «Конакбаєв Віталій
     * Петрович». The role is appended when it is known, because
     * «орендар» is exactly the kind of thing an owner should see and be able to correct.
     *
     * Silent for a household of one — «на рахунку більше нікого» is noise on 268 of the
     * rows that have anybody at all.
     *
     * @param TelegramUser[] $household everyone on the account except the reader
     */
    private static function householdLine(array $household): string
    {
        $names = [];

        foreach ($household as $person) {
            if (!$person instanceof TelegramUser) {
                continue;
            }

            // getDisplayName() is the one definition of "the registry name when the ОСББ
            // knows it, the Telegram one otherwise", and it also survives an entity whose
            // last_name was never initialised.
            $name = $person->getDisplayName();

            // Without a registry name all we have is whatever the person called themselves
            // in Telegram, and that is sometimes «💰» — a real row on прод, and a useless
            // answer to «хто ще на моєму рахунку». The @username is the one other handle a
            // reader can actually match to a person, so it goes alongside. When the
            // accountant has typed the ПІБ there is nothing to add.
            $username = $person->getUsername();

            if ($person->getFullName() === null && $username !== null && $username !== '') {
                $name .= ' (@' . $username . ')';
            }

            $role = TelegramUser::ROLES[(string)$person->getRole()] ?? null;
            $names[] = self::esc($name) . ($role !== null ? ' <i>(' . self::esc(mb_strtolower($role)) . ')</i>' : '');
        }

        return $names === []
            ? ''
            : '👥 <i>На цьому рахунку також:</i> ' . implode(', ', $names) . "\n";
    }

    /**
     * Everyone else on the reader's account.
     *
     * Resolved through the container at render time like the other menu lookups, and
     * failing to an empty list: a decoration must never cost somebody the menu.
     *
     * @return TelegramUser[]
     */
    private static function household(Nutgram $bot, ?Account $account): array
    {
        if (!$account instanceof Account) {
            return [];
        }

        try {
            $me = $bot->getContainer()->get(TelegramUserService::class);
            $current = $me instanceof TelegramUserService ? $me->getCurrentUser() : null;

            return array_values(array_filter(
                $account->getUsers()->toArray(),
                static fn (TelegramUser $u): bool => $u->getId() !== $current?->getId(),
            ));
        } catch (\Throwable) {
            return [];
        }
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
    /**
     * Whether the arrears on this flat are this reader's business.
     *
     * False for a tenant. They are linked to the account so the bot knows where they live —
     * that is what admits them to the house chat, lets them book the альтанка and lets them
     * report a broken lift — and none of it makes the owner's debt theirs. Until 08.09.2026
     * the bot said otherwise on every /start: the figure sat beside their особовий рахунок
     * and the board marked it «📌 Ваша квартира».
     *
     * The board itself still renders for them: it names every flat in the house to every
     * resident, so their neighbour reads the same line, and hiding it would leave a tenant
     * less informed than anybody else while protecting nothing.
     */
    public static function owesForTheFlat(Nutgram $bot): bool
    {
        try {
            $users = $bot->getContainer()->get(TelegramUserService::class);
            $user = $users instanceof TelegramUserService ? $users->getCurrentUser() : null;

            // Unknown reader: say nothing about anybody's debt rather than guess.
            return $user?->owesForTheFlat() ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

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

            return $board->menuBlock($account, self::owesForTheFlat($bot));
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
                InlineKeyboardButton::make(self::votingLabel($bot, $account), callback_data: 'voting-menu'),
            );

        // The resident's pass. It used to appear only while a booking was running — right
        // for a booking ticket, and the reason almost nobody knew it existed. Since
        // 09.09.2026 every confirmed resident carries one and every confirmed resident can
        // read one, blocked or not: a block decides whether somebody may book, never
        // whether they live here. No query per render any more either.
        if (self::mayHoldQr($bot, $account)) {
            $markup->addRow(
                InlineKeyboardButton::make(
                    '🪪 Мій QR-код',
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

    /**
     * «🗳️ Голосування (1)» — the house is deciding something right now.
     *
     * Without it the main menu says nothing about an open vote, so the only people who
     * find one are those who happen to open the section or who read the chat post. A vote
     * runs a week and ends on a count: the residents it misses are exactly the ones whose
     * ballots it needed. Same shape as «🔧 Заявки (5)» and «🛠 Послуги (3)» — count first,
     * label unchanged when there is nothing to say.
     */
    private static function votingLabel(Nutgram $bot, ?Account $account): string
    {
        try {
            $service = $bot->getContainer()->get(BlockVoteService::class);

            if ($service instanceof BlockVoteService) {
                $open = $service->openVoteCount($account);

                return $open > 0 ? sprintf('🗳️ Голосування (%d)', $open) : '🗳️ Голосування';
            }
        } catch (\Throwable) {
            // A count is decoration; the button must appear either way.
        }

        return '🗳️ Голосування';
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
     * May this resident carry a QR pass? Decides whether «🪪 Мій QR-код» is on the menu.
     *
     * The rule itself lives in `GuardService::mayHoldQr()` — the same one the handler
     * checks, so a button that is drawn cannot lead to a refusal. Fails closed, like every
     * other container lookup here.
     */
    private static function mayHoldQr(Nutgram $bot, ?Account $account): bool
    {
        try {
            $guard = $bot->getContainer()->get(GuardService::class);

            return $guard instanceof GuardService && $guard->mayHoldQr($account);
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
