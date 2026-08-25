<?php

namespace App\Telegram\Rental\Command;

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
 * "➕ Здаю квартиру": four short steps — rooms, price, description, confirm — then the
 * listing goes live in the 🔑 Оренда list for RentalListing::LIFETIME_DAYS days.
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

    protected ?string $step = 'askRooms';

    public ?int $rooms = null;
    public ?int $price = null;
    public ?string $description = null;

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
        if ($bot->message()?->photo) {
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

        return parent::__invoke($bot, ...$parameters);
    }

    public function askRooms(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$account) {
            $bot->sendMessage(
                text: "Ваш аккаунт не підтверджений ОСББ — опублікувати оголошення не вийде.\n"
                    . "Зв'яжіться з Аліною Бухгалтером (+380 93 658 32 02).",
                parse_mode: ParseMode::HTML,
            );
            $this->end();
            return;
        }

        if (!$this->rentalService->canPublish($account)) {
            $bot->sendMessage(
                text: 'Оголошення про оренду доступні лише для квартир.',
                parse_mode: ParseMode::HTML,
            );
            $this->end();
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
            text: "🔑 <b>Оголошення про оренду</b>\n\n"
                . 'Квартиру, адресу та площу візьмемо з вашого особового рахунку — '
                . "їх вводити не треба.\n\n"
                . 'Скільки кімнат?',
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
            text: "💰 Яка ціна, грн на місяць?\n\n"
                . '<i>Надішліть число, наприклад 12000.</i>',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('Договірна', callback_data: 'price:none'))
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        $this->next('askDescription');
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

            $price = (int)$digits;

            if ($price < RentalListing::PRICE_MIN || $price > RentalListing::PRICE_MAX) {
                $bot->sendMessage(
                    text: sprintf(
                        '⚠️ Ціна виглядає помилковою. Вкажіть суму від %d до %d грн на місяць.',
                        RentalListing::PRICE_MIN,
                        RentalListing::PRICE_MAX,
                    ),
                    parse_mode: ParseMode::HTML,
                );
                return;
            }

            $this->price = $price;
        }

        $bot->sendMessage(
            text: "📝 Додайте короткий опис — меблі, техніка, з ким можна (до "
                . RentalListing::DESCRIPTION_MAX . " символів).\n\n"
                . '<i>Не пишіть тут номер телефону: список бачить весь будинок, '
                . 'а охочі напишуть вам у Telegram.</i>',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('Пропустити', callback_data: 'desc:none'))
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        $this->next('confirm');
    }

    public function confirm(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data ?? '';

            if ($data === 'cancel') {
                $this->cancel($bot);
                return;
            }

            if ($data === 'publish') {
                $this->publish($bot);
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
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$account) {
            $bot->sendMessage(text: '⚠️ Не вдалося визначити ваш аккаунт. Спробуйте пізніше.');
            $this->end();
            return;
        }

        $listing = $this->rentalService->publish(
            $account,
            $user,
            $this->rooms,
            $this->price,
            $this->description,
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
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        $head = array_filter([
            $account ? 'кв. ' . self::esc((string)$account->getApartmentNumber()) : null,
            $this->rooms === null ? null : ($this->rooms >= 4 ? '4+ кімн.' : $this->rooms . '-кімн.'),
        ]);

        $lines = [
            '🏠 <b>' . implode(' · ', $head) . '</b>',
            '💰 ' . ($this->price === null
                ? 'ціна договірна'
                : number_format($this->price, 0, ',', ' ') . ' грн/міс'),
        ];

        if ($this->description) {
            $lines[] = self::esc($this->description);
        }

        return implode("\n", $lines);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
