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
 * "➕ Пропоную послугу": what you do, and how to reach you. Two questions, and the second
 * one is a yes/no.
 *
 * **The advert need not be about the person posting it.** «Я хочу розмістити телефон свого
 * друга електрика» — so the number is a plain field. Their own is offered as a one-tap
 * button because it is the common case and it is already in our database, but it is a tap
 * and not a default: that number is there because they gave it to the ОСББ for
 * нарахування. Any other number is typed in, and the prompt says out loud that somebody
 * else's number goes on a board the house reads, so ask them first — the responsibility
 * sits with the person publishing it, which is the only place it can honestly sit.
 *
 * **The price is deliberately not asked.** It shipped as a step on 08.09.2026 and came
 * straight back out the same day: what a job costs is settled between the two people, after
 * one of them has said what needs doing, and a number written a month earlier into a
 * classified is either a guess or a promise nobody meant to make. «від 500 грн» on a card
 * does not save the conversation that follows it — it only gives the reader a figure to be
 * disappointed by. The same reasoning took out the free-text «розкажіть про себе»: the
 * board is an index of who does what, not a CV, and every extra step is a reason to close
 * the bot and write in the chat instead. What is left is the two facts a neighbour actually
 * needs — the trade and a way to reach the person — plus photos, which are added afterwards
 * and say more about a плиточник than any paragraph.
 *
 * **The first question is not a category.** A keyboard of trades («Сантехніка»,
 * «Електрика», «Ремонт») fails on the first плиточник: he is none of those, so either the
 * keyboard grows a button per trade until it is unreadable, or he picks the closest wrong
 * one and becomes invisible to whoever searched for tiling. A free line in his own words
 * fits everybody and is what the index button shows.
 *
 * No photo step, on purpose. A photo here would collide with the pavilion-photo obligation:
 * an active conversation swallows every photo the user sends, and telling "фото моєї
 * роботи" apart from "фото альтанки" inside a ~1-hour obligation window is not worth
 * blocking a resident who did send their evidence. Work photos are added afterwards from a
 * web page — see ServiceMenuCommand::photoLink().
 */
class ServicePublish extends Conversation
{
    public const START_CALLBACK = 'svc:new';

    protected ?string $step = 'askTitle';

    public ?string $title = null;
    public ?string $phone = null;

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
                . '<i>Наприклад: Плиточник · Електрик · Манікюр вдома · '
                . "Репетитор з англійської · Вигул собак</i>\n\n"
                . 'Цей рядок буде на кнопці у списку, тому коротко — до '
                . ServiceOffer::TITLE_MAX . " символів.\n\n"
                . '<i>Про ціну не питаємо — її ви обговорите із замовником напряму, '
                . 'коли він скаже, що саме треба.</i>',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        $this->next('askPhone');
    }

    /**
     * The number to ring — theirs, or their electrician's.
     *
     * Their own is a button rather than a default: it sits in our database because they
     * gave it to the ОСББ for нарахування, and publishing it to the house is a separate
     * decision. Any other number is typed in, and the prompt says plainly that it goes on a
     * board the whole house reads — the person publishing it is the one who can ask its
     * owner, so that is where the sentence puts the responsibility.
     *
     * Skipping is allowed. Roughly half of residents have no @username, so for them the
     * «✍️ Написати» button does not exist and the bot relays instead; a card with no number
     * still reaches its poster.
     */
    public function askPhone(Nutgram $bot): void
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

        $own = RentalListingService::formatPhone(
            $this->telegramUserService->getCurrentUser()?->getPhoneNumber()
        );

        $markup = InlineKeyboardMarkup::make();

        if ($own !== null) {
            $markup->addRow(InlineKeyboardButton::make(
                '📞 Мій номер: ' . $own,
                callback_data: 'phone:own',
            ));
        }

        $markup
            ->addRow(InlineKeyboardButton::make('✈️ Без номера — писати мені в Telegram', callback_data: 'phone:none'))
            ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel'));

        $bot->sendMessage(
            text: "📞 <b>Який номер показувати?</b>\n\n"
                . "Надішліть номер повідомленням — свій або майстра, якого ви радите.\n\n"
                . '<i>Оголошення бачать усі підтверджені мешканці будинку. Якщо номер не ваш — '
                . "спитайте спершу в його власника.</i>",
            parse_mode: ParseMode::HTML,
            reply_markup: $markup,
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

            if ($data === 'phone:own') {
                $this->phone = RentalListingService::formatPhone(
                    $this->telegramUserService->getCurrentUser()?->getPhoneNumber()
                );
                $this->showPreview($bot);

                return;
            }

            if ($data === 'phone:none') {
                $this->phone = null;
                $this->showPreview($bot);
            }

            return;
        }

        // A typed number. Only reachable before the preview — afterwards the step accepts
        // callbacks only, so a stray message cannot drop a half-built offer.
        if ($this->phone !== null) {
            return;
        }

        $typed = RentalListingService::formatPhone((string)$bot->message()?->text);

        if ($typed === null) {
            $bot->sendMessage(
                text: '⚠️ Не схоже на український номер. Надішліть у вигляді '
                    . '<b>0501234567</b> або <b>+380501234567</b>, '
                    . 'або оберіть кнопку нижче.',
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        $this->phone = $typed;
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

        $offer = $this->offerService->publish($account, $user, $this->title, $this->phone);

        $bot->sendMessage(
            text: "✅ <b>Оголошення опубліковано</b>\n\n"
                . $this->offerService->describe($offer) . "\n\n"
                . 'Його бачать усі підтверджені мешканці в розділі «🛠 Послуги». '
                . 'За ' . ServiceOffer::RENEW_PROMPT_BEFORE_DAYS . ' дні до кінця строку '
                . 'запитаємо, чи ще актуально.',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                // Photos are the single biggest thing an offer can gain now that the card
                // carries nothing but the trade — and this is the one moment the author is
                // definitely still holding the phone.
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

        $lines = ['🛠 <b>' . self::esc((string)$this->title) . '</b>'];

        if ($this->phone !== null) {
            $lines[] = '📞 ' . self::esc($this->phone);
        }

        $lines[] = '👤 <i>Розмістив: ' . ServiceOfferService::place($account) . '</i>';

        return implode("\n", $lines);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
