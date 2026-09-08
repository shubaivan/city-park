<?php

namespace App\Telegram\ServiceOffer\Command;

use App\Entity\ServiceOffer;
use App\Service\OsbbContacts;
use App\Service\PhotoUploadFlow;
use App\Service\RentalListingService;
use App\Service\ServiceOfferService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "➕ Пропоную послугу": four short steps — what you do, price, description, phone
 * consent — then the offer is live for ServiceOffer::LIFETIME_DAYS days.
 *
 * **The first question is not a category.** The obvious design was a keyboard of trades
 * («Сантехніка», «Електрика», «Ремонт») and it fails on the first плиточник: they are
 * none of those, so either the keyboard grows a button per trade until it is unreadable,
 * or they pick the closest wrong one and become invisible to whoever searches for tiling.
 * A free line in their own words fits everybody, is what the index button shows, and —
 * unlike a fixed list — does not have to be guessed right in advance. See the ServiceOffer
 * class comment for when categories would earn their place.
 *
 * No photo step, on purpose. A photo here would collide with the pavilion-photo
 * obligation: an active conversation swallows every photo the user sends, and telling
 * "фото моєї роботи" apart from "фото альтанки" inside a ~1-hour obligation window is not
 * worth blocking a resident who did send their evidence. Work photos are added afterwards
 * from a web page — see ServiceMenuCommand::photoLink().
 */
class ServicePublish extends Conversation
{
    public const START_CALLBACK = 'svc:new';

    protected ?string $step = 'askTitle';

    public ?string $title = null;
    public ?string $priceNote = null;
    public ?string $description = null;
    public bool $showPhone = false;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private ServiceOfferService $offerService,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /**
     * Same guard every multi-step conversation in this bot must carry: Nutgram routes
     * EVERY update from a user with a live conversation here, a pavilion photo included.
     * Without this the photo is swallowed and the resident is blocked for evidence they
     * did send. See PhotoUploadFlow::interceptConversationPhoto().
     *
     * Guarded on isIncomingPhoto($bot), never on $bot->message()?->photo: on a callback
     * query $bot->message() is the message the button hangs on, and a card with pictures
     * *is* a photo message — the naive check fires on a tap and freezes the conversation
     * with nothing in any log.
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
                        . 'Щоб опублікувати послугу, відкрийте «🛠 Послуги» ще раз. '
                        . 'Фото своїх робіт додаються окремо, вже до готового оголошення.',
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

    public function askTitle(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$this->offerService->canPublish($account)) {
            $bot->sendMessage(
                text: "Ваш аккаунт не підтверджений ОСББ — опублікувати оголошення не вийде.\n"
                    . "Зв'яжіться з бухгалтером ОСББ:\n" . OsbbContacts::ACCOUNTANT_LINE,
                parse_mode: ParseMode::HTML,
            );
            $this->end();

            return;
        }

        $bot->sendMessage(
            text: "🛠 <b>Оголошення про послугу</b>\n\n"
                . "Напишіть одним рядком, що ви робите — так, як сказали б сусідові.\n\n"
                . "<i>Наприклад: Плиточник · Електрик · Манікюр вдома · "
                . "Репетитор з англійської · Вигул собак</i>\n\n"
                . 'Цей рядок буде на кнопці у списку, тому коротко — до '
                . ServiceOffer::TITLE_MAX . ' символів.',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        $this->next('askPrice');
    }

    public function askPrice(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            if (($bot->callbackQuery()->data ?? '') === 'cancel') {
                $this->cancel($bot);
            }

            return;
        }

        $title = trim((string)$bot->message()?->text);

        if ($title === '') {
            return;
        }

        // Long enough to be a trade and not a greeting. The cap is applied by the entity;
        // this is the floor, and it exists because «+» and «..» were the first two things
        // typed into the equivalent field on the complaints register.
        if (mb_strlen($title, 'UTF-8') < 3) {
            $bot->sendMessage(
                text: '⚠️ Занадто коротко. Напишіть, що саме ви робите — наприклад «Плиточник».',
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        $this->title = mb_substr($title, 0, ServiceOffer::TITLE_MAX, 'UTF-8');

        $bot->sendMessage(
            text: "💰 <b>Скільки це коштує?</b>\n\n"
                . "Напишіть як вам зручно — точну суму, «від», за годину чи за метр.\n\n"
                . '<i>Наприклад: від 500 грн · 300 грн/год · 250 грн/м² · по домовленості</i>',
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

            $this->priceNote = null;
        } else {
            $raw = trim((string)$bot->message()?->text);

            if ($raw === '') {
                return;
            }

            $this->priceNote = mb_substr($raw, 0, ServiceOffer::PRICE_MAX, 'UTF-8');
        }

        $bot->sendMessage(
            text: "📝 <b>Розкажіть трохи більше</b> (до " . ServiceOffer::DESCRIPTION_MAX . " символів).\n\n"
                . "Що саме робите, скільки років цим займаєтесь, чи є свій інструмент, "
                . "коли зручно — все, що допоможе сусідові вирішити.\n\n"
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
     * The one question that decides whether a neighbour can actually reach this person.
     *
     * Roughly half the residents have no @username, so for them the "✍️ Написати" button
     * does not exist and the bot falls back to relaying. The number is already in our
     * database; what is missing is permission to publish it. Ask once, show it in full so
     * nobody is surprised by what goes out, and default to keeping it private.
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

            $this->description = mb_substr($text, 0, ServiceOffer::DESCRIPTION_MAX, 'UTF-8');
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
                . 'Показати в ньому ваш номер <b>' . self::esc($phone) . "</b>?\n\n"
                . "<i>Якщо ні — з вами зв'яжуться через Telegram.</i>",
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
        // above, so a mistyped message can't drop the half-built offer.
        $this->next('confirm');
    }

    private function publish(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$account || $this->title === null) {
            $bot->sendMessage(text: '⚠️ Не вдалося опублікувати оголошення. Спробуйте пізніше.');
            $this->end();

            return;
        }

        $offer = $this->offerService->publish(
            $account,
            $user,
            $this->title,
            $this->priceNote,
            $this->description,
            $this->showPhone,
        );

        $bot->sendMessage(
            text: "✅ <b>Оголошення опубліковано</b>\n\n"
                . $this->offerService->describe($offer) . "\n\n"
                . 'Його бачать усі підтверджені мешканці в розділі «🛠 Послуги». '
                . 'За ' . ServiceOffer::RENEW_PROMPT_BEFORE_DAYS . ' дні до кінця строку '
                . 'запитаємо, чи ще актуально.',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                // Photos are the single biggest thing an offer can gain, and this is the
                // one moment the author is definitely still holding the phone.
                ->addRow(InlineKeyboardButton::make(
                    '📷 Додати фото робіт',
                    callback_data: 'svc:photos:' . $offer->getId(),
                ))
                ->addRow(InlineKeyboardButton::make(
                    '🛠 До списку',
                    callback_data: ServiceMenuCommand::MENU_CALLBACK,
                ))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    private function cancel(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Скасовано — оголошення не опубліковано.',
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    '🛠 Послуги',
                    callback_data: ServiceMenuCommand::MENU_CALLBACK,
                ))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    /** What the offer will look like, before anything is written to the database. */
    private function preview(): string
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        $lines = [
            '🛠 <b>' . self::esc((string)$this->title) . '</b>',
            '🏠 <i>' . ServiceOfferService::place($account) . '</i>',
            '💰 ' . self::esc($this->priceNote ?? 'ціна договірна'),
        ];

        if ($this->description) {
            $lines[] = '';
            $lines[] = self::esc($this->description);
        }

        if ($this->showPhone) {
            $phone = RentalListingService::formatPhone($user?->getPhoneNumber());

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
