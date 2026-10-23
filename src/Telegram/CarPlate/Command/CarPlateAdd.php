<?php

namespace App\Telegram\CarPlate\Command;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Service\CarPlateAnnouncer;
use App\Service\CarPlateService;
use App\Service\OsbbContacts;
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

/** «➕ Додати номер авто» — one question: the number. */
class CarPlateAdd extends Conversation
{
    public const START_CALLBACK = 'car:new';

    protected ?string $step = 'askPlate';

    /**
     * A plain property with a default, not a promoted one: a conversation serialised into
     * `var/pools` before this was added comes back without it, and a promoted property
     * would be uninitialised and throw. With the default it is simply null for that one.
     */
    private ?CarPlateAnnouncer $announcer = null;

    /** The number just added, for the optional «паркомісце» step that follows. */
    public ?int $plateId = null;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private CarPlateService $plates,
        private CarPlateCommand $menu,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
        ?CarPlateAnnouncer $announcer = null,
    ) {
        $this->announcer = $announcer;
    }

    /**
     * The guard every multi-step conversation in this bot carries — and this one is the
     * likeliest of all to receive a picture: somebody asked for their car number will
     * photograph the plate. That photo is still pavilion evidence and nothing else.
     * Guarded on isIncomingPhoto(), never on $bot->message()?->photo.
     */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if (PhotoUploadFlow::isIncomingPhoto($bot)) {
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — обробляємо його як фото альтанки, додавання '
                        . 'номера скасовано. Щоб додати авто, відкрийте «🚗 Мої авто» ще раз.',
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

        $account = $this->account();

        if (!$this->plates->mayAdd($account)) {
            $this->end();

            if ($account instanceof Account) {
                $this->menu->list($bot, $account, sprintf(
                    '⚠️ У вас уже %d номерів — це максимум.',
                    CarPlate::MAX_PER_ACCOUNT,
                ));
            } else {
                $bot->sendMessage(text: 'Спершу підтвердіть номер телефону: /phone');
            }

            return;
        }

        $bot->sendMessage(
            text: "🚗 Напишіть номер авто так, як на табличці, наприклад: <b>СА 1234 АВ</b>\n\n"
                . "<b>Один номер в одному повідомленні.</b> Друге авто додасте одразу після цього.\n"
                // People describe a car the way they would to a person — «СА1322КН, Mazda
                // CX5, буд 25 кв 25» (23.10.2026) — and the prompt never said not to.
                . "Марку й адресу писати не потрібно — вашу квартиру бот уже знає.\n\n"
                . "<i>Можна українськими чи латинськими літерами, великими чи малими, з пробілами "
                . "чи без. Підходять і номери електромобілів, іноземні та індивідуальні.</i>",
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: CarPlateCommand::MENU_CALLBACK)),
        );

        $this->next('save');
    }

    public function save(Nutgram $bot): void
    {
        // A tap on any button while this is live means «скасувати»: Nutgram routes every
        // update from this user here, so an unhandled callback would spin forever.
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
            $this->end();
            ($this->menu)($bot);

            return;
        }

        $account = $this->account();

        if (!$account instanceof Account) {
            $this->end();
            $bot->sendMessage(text: 'Спершу підтвердіть номер телефону: /phone');

            return;
        }

        $raw = (string)$bot->message()?->text;
        $result = $this->plates->add($account, $this->telegramUserService->getCurrentUser(), $raw);

        if ($result === CarPlateService::INVALID) {
            // Kept, so the next «не можу додати» can be answered from the log instead of by
            // guessing — two of those arrived on the first afternoon and nothing said what
            // had been typed. The photo channel because it is the one written unbuffered.
            $this->photoLogger?->info('car plate refused', [
                'chat_id' => $bot->chatId(),
                'typed' => mb_substr($raw, 0, 60),
                'reason' => PlateKey::problem($raw),
            ]);

            // Stay on this step: the commonest mistake is a typo, and the person is already
            // holding the keyboard. The reason names what to change.
            $bot->sendMessage(
                text: sprintf(
                    "Не вийшло додати номер: %s\n\nНапишіть ще раз, наприклад: <b>СА 1234 АВ</b>\n\n"
                        . "Якщо не виходить, напишіть розробнику:\n%s",
                    htmlspecialchars((string)PlateKey::problem($raw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    OsbbContacts::DEV_LINE,
                ),
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: CarPlateCommand::MENU_CALLBACK)),
            );

            return;
        }

        if ($result === CarPlateService::ADDED) {
            $this->announcer?->afterAdded($account);

            $plate = $this->plates->lookUp($raw);

            if ($plate !== null) {
                // The number is saved; the space is a second, optional question, asked
                // while the person is still here — and «Пропустити» loses nothing.
                $this->plateId = $plate->getId();
                $bot->sendMessage(
                    text: sprintf('✅ Номер <b>%s</b> додано.', $plate->getDisplay()),
                    parse_mode: ParseMode::HTML,
                );
                CarPlateSpot::sendQuestion($bot, $plate);
                $this->next('saveSpot');

                return;
            }
        }

        $this->end();

        $display = PlateKey::display(PlateKey::of($raw));

        $notice = match ($result) {
            CarPlateService::ADDED => sprintf('✅ Номер <b>%s</b> додано.', $display),
            CarPlateService::ALREADY_OURS => sprintf('ℹ️ Номер <b>%s</b> уже є у вашому списку.', $display),
            CarPlateService::TAKEN => sprintf(
                "⚠️ Номер <b>%s</b> уже внесено іншими мешканцями.\n"
                    . "Якщо це ваше авто, напишіть, і ми розберемося:\n%s",
                $display,
                OsbbContacts::DEV_LINE,
            ),
            CarPlateService::FULL => sprintf('⚠️ У вас уже %d номерів — це максимум.', CarPlate::MAX_PER_ACCOUNT),
            default => '',
        };

        $this->menu->list($bot, $account, $notice);
    }

    public function saveSpot(Nutgram $bot): void
    {
        $this->end();
        $account = $this->account();

        if (!$account instanceof Account) {
            $bot->isCallbackQuery() && $bot->answerCallbackQuery();
            ($this->menu)($bot);

            return;
        }

        // «Пропустити», or any other tap: the number stays, without a space.
        if ($bot->isCallbackQuery()) {
            $this->menu->list($bot, $account);

            return;
        }

        $plate = $this->plates->own($account, (int)$this->plateId);

        if ($plate === null) {
            $this->menu->list($bot, $account);

            return;
        }

        // A new space means a new answer to «власне чи орендоване?»; an object already
        // joined stays joined — undoing that is the panel's, like every other unlink.
        $plate->setSpotOwned(null)->setParkingAccount(null)->setParkingLinked(false);
        $this->plates->setSpot($plate, (string)$bot->message()?->text);
        $this->menu->afterSpot($bot, $account, $plate, CarPlateSpot::savedNotice($plate));
    }

    private function account(): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }
}
