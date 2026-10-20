<?php

namespace App\Telegram\CarPlate\Command;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Service\CarPlateService;
use App\Service\PhotoUploadFlow;
use App\Service\TelegramUserService;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🅿️ Паркомісце» for a car already on the list — one question, never required.
 *
 * The same question is asked right after a number is added (`CarPlateAdd`); this is the way
 * back to it for the cars written down before it existed, and for a space that changed.
 */
class CarPlateSpot extends Conversation
{
    public const START_PREFIX = 'car:spot:';

    protected ?string $step = 'ask';

    public ?int $plateId = null;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private CarPlateService $plates,
        private CarPlateCommand $menu,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /** The guard every multi-step conversation carries; isIncomingPhoto(), never ->photo. */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if (PhotoUploadFlow::isIncomingPhoto($bot)) {
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — обробляємо його як фото альтанки. Паркомісце не '
                        . 'змінено; відкрийте «🚗 Мої авто» ще раз, щоб його вказати.',
                    '📷 Фото тут не потрібне — напишіть номер паркомісця текстом, наприклад: <b>138</b>',
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

    public function ask(Nutgram $bot): void
    {
        $bot->isCallbackQuery() && $bot->answerCallbackQuery();

        $data = $bot->isCallbackQuery() ? (string)($bot->callbackQuery()->data ?? '') : '';
        $account = $this->account();
        $plate = $account ? $this->plates->own($account, (int)substr($data, strlen(self::START_PREFIX))) : null;

        if (!$account instanceof Account || $plate === null) {
            $this->end();
            ($this->menu)($bot);

            return;
        }

        $this->plateId = $plate->getId();
        self::sendQuestion($bot, $plate);
        $this->next('save');
    }

    public function save(Nutgram $bot): void
    {
        // Any tap while this is live means «пропустити» — an unhandled callback would spin.
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
            $this->end();
            ($this->menu)($bot);

            return;
        }

        $account = $this->account();
        $plate = $account ? $this->plates->own($account, (int)$this->plateId) : null;
        $this->end();

        if (!$account instanceof Account || $plate === null) {
            ($this->menu)($bot);

            return;
        }

        // A new space means a new answer to «власне чи орендоване?»; an object already
        // joined stays joined — undoing that is the panel's, like every other unlink.
        $plate->setSpotOwned(null)->setParkingAccount(null)->setParkingLinked(false);
        $this->plates->setSpot($plate, (string)$bot->message()?->text);
        $this->menu->afterSpot($bot, $account, $plate, self::savedNotice($plate));
    }

    /** Shared with CarPlateAdd, which asks the same question straight after a new number. */
    public static function sendQuestion(Nutgram $bot, CarPlate $plate): void
    {
        $bot->sendMessage(
            text: self::question($plate),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('⏭ Пропустити', callback_data: CarPlateCommand::MENU_CALLBACK)),
        );
    }

    public static function question(CarPlate $plate): string
    {
        return sprintf(
            "🅿️ Де стоїть <b>%s</b>? Якщо є паркомісце — напишіть його номер, наприклад: "
                . "<b>138</b> або <b>45, орендоване</b>.\n\n"
                . "<i>Необов'язково. Якщо паркомісця немає — натисніть «Пропустити».</i>",
            $plate->getDisplay(),
        );
    }

    public static function savedNotice(CarPlate $plate): string
    {
        return $plate->getParkingSpot() !== null
            ? sprintf(
                '✅ <b>%s</b> — паркомісце: <b>%s</b>.',
                $plate->getDisplay(),
                htmlspecialchars($plate->getParkingSpot(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            )
            : sprintf('✅ <b>%s</b> — без паркомісця.', $plate->getDisplay());
    }

    private function account(): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }
}
