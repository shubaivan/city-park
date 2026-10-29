<?php

namespace App\Telegram\Rental\Command;

use App\Entity\Account;
use App\Service\OsbbContacts;
use App\Entity\RentalListing;
use App\Service\PhotoUploadFlow;
use App\Service\RentalListingService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "🔑 Здаю" / "🏡 Продаю": which object (only when the household has several), rooms
 * (flats only), price, description, contact, confirm — then the listing goes live in the 🔑 Оренда list for RentalListing::LIFETIME_DAYS days.
 *
 * No photo step on purpose. A photo here would collide with the pavilion-photo
 * obligation: an active conversation swallows every photo the user sends, and telling
 * apart "фото квартири для оголошення" from "фото альтанки" inside a ~1-hour obligation
 * window is not worth the risk of blocking a resident who did send their evidence.
 * Owners send apartment photos directly to whoever answers the listing.
 */
class RentalPublish extends Conversation
{
    public const START_CALLBACK = 'rent:new';

    /**
     * Selling has its own button rather than a first question inside the conversation:
     * the owner already knows which of the two they are doing, and a step that asks it
     * back is a step they can abandon on.
     */
    public const START_SALE_CALLBACK = 'rent:new-sale';

    protected ?string $step = 'askRooms';

    public string $deal = RentalListing::DEAL_RENT;

    /**
     * The object being advertised — one of the household's (flat, комора, паркомісце).
     * Null on a conversation started before this existed: those fall back to the
     * resident's own account, which is what they were publishing then.
     */
    public ?int $accountId = null;
    public ?int $rooms = null;
    public ?int $price = null;
    public ?string $description = null;
    public bool $showPhone = false;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private RentalListingService $rentalService,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /**
     * Same guard every multi-step conversation in this bot must carry: Nutgram routes
     * EVERY update from a user with a live conversation here, a pavilion photo included.
     * Without this the photo is swallowed and the resident is blocked for evidence they
     * did send. See PhotoUploadFlow::interceptConversationPhoto().
     */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if (PhotoUploadFlow::isIncomingPhoto($bot)) {
            // Must never throw — an exception here answers /hook with 500 and Telegram
            // retries the same photo for an hour.
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — обробляємо його, створення оголошення скасовано. '
                        . 'Щоб опублікувати оголошення, відкрийте «🔑 Оренда» ще раз.',
                );
            } catch (\Throwable $e) {
                $this->photoLogger?->error('photo interception failed outright', [
                    'chat_id' => $bot->chatId(),
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        }

        if ($bot->isCallbackQuery() && ($bot->callbackQuery()->data ?? '') === self::START_SALE_CALLBACK) {
            $this->deal = RentalListing::DEAL_SALE;
        }

        return parent::__invoke($bot, ...$parameters);
    }

    public function askRooms(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if ($account && $bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data ?? '';

            if ($data === 'cancel') {
                $this->cancel($bot);
                return;
            }

            if (str_starts_with($data, 'obj:')) {
                $picked = (int)substr($data, strlen('obj:'));

                foreach ($this->rentalService->objectsFor($account) as $object) {
                    if ($object->getId() === $picked) {
                        $this->accountId = $picked;
                    }
                }
            }
        }

        if (!$account) {
            $bot->sendMessage(
                text: "Ваш акаунт не підтверджений ОСББ — опублікувати оголошення не вийде.\n"
                    . "Зв'яжіться з бухгалтером ОСББ:\n" . OsbbContacts::ACCOUNTANT_LINE,
                parse_mode: ParseMode::HTML,
            );
            $this->end();
            return;
        }

        $sale = $this->deal === RentalListing::DEAL_SALE;
        $title = $sale ? "🏡 <b>Оголошення про продаж</b>\n\n" : "🔑 <b>Оголошення про оренду</b>\n\n";
        $objects = $this->rentalService->objectsFor($account);

        // A household with a flat and a комора has to say which one it is about; one
        // object needs no question.
        if ($this->accountId === null && count($objects) > 1) {
            $markup = InlineKeyboardMarkup::make();

            foreach ($objects as $object) {
                $markup->addRow(InlineKeyboardButton::make(
                    RentalListingService::placePlain($object),
                    callback_data: 'obj:' . $object->getId(),
                ));
            }

            $markup->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel'));

            $bot->sendMessage(
                text: $title . 'Що саме ' . ($sale ? 'продаєте' : 'здаєте') . '?',
                parse_mode: ParseMode::HTML,
                reply_markup: $markup,
            );

            $this->next('askRooms');
            return;
        }

        $object = $this->object() ?? $account;

        // Rooms are a question about a flat; a комора or a паркомісце goes straight to
        // the price.
        if (!$object->isApartment()) {
            $this->rooms = null;
            $bot->sendMessage(
                text: $title . '<b>' . RentalListingService::place($object) . "</b> — адресу та площу "
                    . "візьмемо з особового рахунку.\n\n" . $this->pricePrompt(),
                parse_mode: ParseMode::HTML,
                reply_markup: $this->priceMarkup(),
            );

            $this->next('askDescription');
            return;
        }

        $markup = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('1', callback_data: 'rooms:1'),
                InlineKeyboardButton::make('2', callback_data: 'rooms:2'),
                InlineKeyboardButton::make('3', callback_data: 'rooms:3'),
                InlineKeyboardButton::make('4+', callback_data: 'rooms:4'),
            )
            ->addRow(InlineKeyboardButton::make('Не вказувати', callback_data: 'rooms:0'))
            ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel'));

        $bot->sendMessage(
            text: $title
                . '<b>' . RentalListingService::place($object) . '</b> — адресу та площу візьмемо '
                . "з особового рахунку, їх вводити не треба.\n\n"
                . 'Скільки кімнат?'
                // The one place somebody notices their комора is missing: it is a separate
                // особовий рахунок, and only the accountant can join it to the household.
                . (count($objects) === 1
                    ? "\n\n<i>Хочете " . ($sale ? 'продати' : 'здати') . ' комору чи паркомісце, а тут лише квартира? '
                        . "Попросіть бухгалтера ОСББ додати їх до вашого рахунку в боті:</i>\n"
                        . OsbbContacts::ACCOUNTANT_LINE
                    : ''),
            parse_mode: ParseMode::HTML,
            reply_markup: $markup,
        );

        $this->next('askPrice');
    }

    public function askPrice(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? ($bot->callbackQuery()->data ?? '') : '';

        if ($data === 'cancel') {
            $this->cancel($bot);
            return;
        }

        if (!str_starts_with($data, 'rooms:')) {
            $this->askRooms($bot);
            return;
        }

        $rooms = (int)substr($data, strlen('rooms:'));
        $this->rooms = $rooms > 0 ? min($rooms, 4) : null;

        $bot->editMessageText(
            text: $this->pricePrompt(),
            parse_mode: ParseMode::HTML,
            reply_markup: $this->priceMarkup(),
        );

        $this->next('askDescription');
    }

    private function pricePrompt(): string
    {
        if ($this->deal === RentalListing::DEAL_SALE) {
            return "💰 Яка ціна, грн?\n\n<i>Надішліть число, наприклад 1850000.</i>";
        }

        $example = $this->object()?->isApartment() === false ? '2000' : '12000';

        return "💰 Яка ціна, грн на місяць?\n\n<i>Надішліть число, наприклад {$example}.</i>";
    }

    private function priceMarkup(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('Договірна', callback_data: 'price:none'))
            ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel'));
    }

    /**
     * The object picked at the start, re-checked against the household every time it is
     * read: a conversation outlives the keyboard it began on, and somebody unlinked from
     * the комора in between must not publish it.
     */
    private function object(): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$account) {
            return null;
        }

        if ($this->accountId === null) {
            return $account;
        }

        foreach ($this->rentalService->objectsFor($account) as $object) {
            if ($object->getId() === $this->accountId) {
                return $object;
            }
        }

        return null;
    }

    public function askDescription(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data ?? '';

            if ($data === 'cancel') {
                $this->cancel($bot);
                return;
            }

            if ($data !== 'price:none') {
                return;
            }

            $this->price = null;
        } else {
            $raw = trim((string)$bot->message()?->text);
            $digits = preg_replace('/\D+/', '', $raw);

            if ($digits === '') {
                $bot->sendMessage(
                    text: '⚠️ Не зрозумів ціну. Надішліть число, наприклад <b>12000</b>, '
                        . 'або натисніть «Договірна».',
                    parse_mode: ParseMode::HTML,
                );
                return;
            }

            // No bounds: what to ask is the owner's call, for a flat and a комора, rent or
            // sale alike (Иван, 29.10.2026). The one check left is technical — the column is
            // an integer, so a number it cannot hold is read as not understood.
            $price = strlen($digits) <= 9 ? (int)$digits : 0;

            if ($price <= 0) {
                $bot->sendMessage(
                    text: '⚠️ Не зрозумів ціну. Надішліть число, наприклад <b>12000</b>, '
                        . 'або натисніть «Договірна».',
                    parse_mode: ParseMode::HTML,
                );
                return;
            }

            $this->price = $price;
        }

        $bot->sendMessage(
            text: ($this->object()?->isApartment() === false
                    ? "📝 Додайте короткий опис — під'їзд, поверх, доступ, що там можна тримати (до "
                    : "📝 Додайте короткий опис — під'їзд, поверх, меблі, техніка, з ким можна (до ")
                . RentalListing::DESCRIPTION_MAX . " символів).\n\n"
                . '<i>Номер телефону сюди писати не треба — про нього спитаємо окремо '
                . 'на наступному кроці.</i>',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('Пропустити', callback_data: 'desc:none'))
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        $this->next('askContact');
    }

    /**
     * The one question that decides whether a neighbour can actually reach this owner.
     *
     * Roughly half the residents have no @username, so for them the "✍️ Написати" button
     * does not exist and the bot falls back to relaying — which used to dead-end when the
     * interested neighbour had no username either. The number is already in our database;
     * what was missing is the owner's permission to publish it. Ask once, show the number
     * in full so nobody is surprised by what goes out, and default to keeping it private.
     */
    public function askContact(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data ?? '';

            if ($data === 'cancel') {
                $this->cancel($bot);
                return;
            }

            if ($data !== 'desc:none') {
                return;
            }

            $this->description = null;
        } else {
            $text = trim((string)$bot->message()?->text);

            if ($text === '') {
                return;
            }

            $this->description = mb_substr($text, 0, RentalListing::DESCRIPTION_MAX, 'UTF-8');
        }

        $phone = RentalListingService::formatPhone(
            $this->telegramUserService->getCurrentUser()?->getPhoneNumber()
        );

        // Nothing to offer — skip the question rather than ask about a number we don't have.
        if ($phone === null) {
            $this->showPhone = false;
            $this->showPreview($bot);
            return;
        }

        $bot->sendMessage(
            text: "📞 <b>Як з вами зв'язуватись?</b>\n\n"
                . 'Оголошення бачать усі підтверджені мешканці будинку. '
                . "Показати в ньому ваш номер <b>" . self::esc($phone) . "</b>?\n\n"
                . '<i>Якщо ні — з вами зв\'яжуться через Telegram.</i>',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('📞 Так, показувати номер', callback_data: 'contact:phone'))
                ->addRow(InlineKeyboardButton::make('✈️ Ні, тільки Telegram', callback_data: 'contact:tg'))
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        $this->next('confirm');
    }

    public function confirm(Nutgram $bot): void
    {
        if (!$bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data ?? '';

        if ($data === 'cancel') {
            $this->cancel($bot);
            return;
        }

        if ($data === 'publish') {
            $this->publish($bot);
            return;
        }

        if ($data !== 'contact:phone' && $data !== 'contact:tg') {
            return;
        }

        $this->showPhone = $data === 'contact:phone';
        $this->showPreview($bot);
    }

    private function showPreview(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: "Публікуємо?\n\n" . $this->preview(),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ Опублікувати', callback_data: 'publish'))
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        // Stay on this step: only "publish" / "cancel" move on, any stray text is ignored
        // above, so a mistyped message can't drop the half-built listing.
        $this->next('confirm');
    }

    private function publish(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $this->object();

        if (!$account) {
            $bot->sendMessage(text: '⚠️ Не вдалося визначити ваш акаунт. Спробуйте пізніше.');
            $this->end();
            return;
        }

        $listing = $this->rentalService->publish(
            $account,
            $user,
            $this->rooms,
            $this->price,
            $this->description,
            $this->showPhone,
            $this->deal,
        );

        $bot->sendMessage(
            text: "✅ <b>Оголошення опубліковано</b>\n\n"
                . $this->rentalService->describe($listing) . "\n\n"
                . 'Його бачать усі мешканці в розділі «🔑 Оренда». '
                . 'За ' . RentalListing::RENEW_PROMPT_BEFORE_DAYS . ' дні до кінця строку '
                . 'запитаємо, чи ще актуально.',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔑 До списку', callback_data: RentalMenuCommand::MENU_CALLBACK))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    private function cancel(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Скасовано — оголошення не опубліковано.',
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔑 Оренда', callback_data: RentalMenuCommand::MENU_CALLBACK))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    /** What the listing will look like, before anything is written to the database. */
    private function preview(): string
    {
        $account = $this->object();

        $head = array_filter([
            $account ? RentalListingService::place($account) : null,
            $this->rooms === null ? null : ($this->rooms >= 4 ? '4+ кімн.' : $this->rooms . '-кімн.'),
        ]);

        $lines = [
            ($this->deal === RentalListing::DEAL_SALE ? '🏡' : '🔑') . ' <b>' . implode(' · ', $head) . '</b>',
            '💰 ' . ($this->price === null
                ? 'ціна договірна'
                : number_format($this->price, 0, ',', ' ') . ($this->deal === RentalListing::DEAL_SALE ? ' грн' : ' грн/міс')),
        ];

        if ($this->description) {
            $lines[] = self::esc($this->description);
        }

        if ($this->showPhone) {
            $phone = RentalListingService::formatPhone(
                $this->telegramUserService->getCurrentUser()?->getPhoneNumber()
            );

            if ($phone) {
                $lines[] = '📞 ' . self::esc($phone);
            }
        }

        return implode("\n", $lines);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
