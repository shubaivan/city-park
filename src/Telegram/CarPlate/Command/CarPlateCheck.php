<?php

namespace App\Telegram\CarPlate\Command;

use App\Service\CarPlateService;
use App\Service\PhotoUploadFlow;
use App\Service\PlateKey;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🚗 Перевірити номер авто» — the guard types a number and is told whose it is.
 *
 * The one place the list is read back in the bot, and only by the guard: it is what makes
 * the list useful the day it starts filling, rather than on the day of a barrier.
 *
 * **«Немає в переліку» is not «чуже».** Writing a number down is voluntary, so on day one
 * almost every resident's car answers «немає», and a guard who reads that as «stranger»
 * turns a neighbour away at his own gate. The answer says so every time.
 *
 * Ends after each answer and offers «🔎 Ще один номер» rather than staying open: a
 * conversation left live swallows whatever the guard types or taps next.
 */
class CarPlateCheck extends Conversation
{
    public const START_CALLBACK = 'car:check';

    protected ?string $step = 'askPlate';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private CarPlateService $plates,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /** The guard every multi-step conversation carries; see CarPlateAdd. */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if (PhotoUploadFlow::isIncomingPhoto($bot)) {
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — перевірку номера скасовано.',
                    '📷 Фото тут не потрібне — напишіть номер авто текстом, наприклад: '
                        . '<b>СА 1234 АВ</b>',
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

    public function askPlate(Nutgram $bot): void
    {
        $bot->isCallbackQuery() && $bot->answerCallbackQuery();

        if (!$this->plates->mayLookUp($this->telegramUserService->getCurrentUser())) {
            $this->end();
            $bot->sendMessage(
                text: 'Перевірка номерів авто — для охорони ЖК.',
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            return;
        }

        $bot->sendMessage(
            text: '🚗 Напишіть номер авто, яке перевіряєте, наприклад: <b>СА 1234 АВ</b>',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: StartCommand::MAIN_MENU_CALLBACK)),
        );

        $this->next('answer');
    }

    public function answer(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
            $this->end();
            StartCommand::send($bot);

            return;
        }

        $raw = (string)$bot->message()?->text;
        $key = PlateKey::of($raw);

        if ($key === '') {
            $bot->sendMessage(
                text: sprintf(
                    "Не схоже на номер авто: %s\nНапишіть, наприклад: <b>СА 1234 АВ</b>",
                    htmlspecialchars((string)PlateKey::problem($raw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                ),
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: StartCommand::MAIN_MENU_CALLBACK)),
            );

            return;
        }

        $this->end();

        $bot->sendMessage(
            text: self::verdict(
                $key,
                $this->plates->lookUp($key)?->getAccount()?->getPlaceLabel(),
                $this->plates->lookUp($key)?->getParkingSpot(),
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔎 Ще один номер', callback_data: self::START_CALLBACK))
                ->addRow(StartCommand::homeButton()),
        );
    }

    /** Static and Nutgram-free: the wording is the whole feature, and a test reads it. */
    public static function verdict(string $key, ?string $place, ?string $spot = null): string
    {
        $display = PlateKey::display($key);

        if ($place !== null && $place !== '') {
            return sprintf(
                "✅ <b>%s</b> — авто мешканця\n%s%s",
                $display,
                htmlspecialchars($place, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $spot !== null && $spot !== ''
                    ? "\n🅿️ Паркомісце: " . htmlspecialchars($spot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : '',
            );
        }

        return sprintf(
            "❓ <b>%s</b> немає в переліку авто ЖК.\n\n"
                . "<i>Це ще не означає, що авто чуже: мешканці вносять номери за бажанням, "
                . "і багато хто ще не вніс.</i>",
            $display,
        );
    }
}
