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
                    '⚠️ У вашої квартири вже %d номерів — це максимум.',
                    CarPlate::MAX_PER_ACCOUNT,
                ));
            } else {
                $bot->sendMessage(text: 'Спершу підтвердіть номер телефону: /phone');
            }

            return;
        }

        $bot->sendMessage(
            text: "🚗 Напишіть номер авто, наприклад: <b>СА 1234 АВ</b>\n\n"
                . '<i>Можна українськими чи латинськими літерами, з пробілами чи без.</i>',
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
            // Stay on this step: the commonest mistake is a typo, and the person is already
            // holding the keyboard.
            $bot->sendMessage(
                text: "Не схоже на номер авто. Потрібен формат <b>2 літери · 4 цифри · 2 літери</b>, "
                    . "наприклад: <b>СА 1234 АВ</b>\n\n"
                    . "<i>Лише літери й цифри номера, без інших слів.</i>\n\n"
                    . "Якщо номер у вашого авто іншого формату (іноземний, індивідуальний), напишіть:\n"
                    . OsbbContacts::DEV_LINE,
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: CarPlateCommand::MENU_CALLBACK)),
            );

            return;
        }

        $this->end();

        if ($result === CarPlateService::ADDED) {
            $this->announcer?->afterAdded($account);
        }

        $display = PlateKey::display(PlateKey::of($raw));

        $notice = match ($result) {
            CarPlateService::ADDED => sprintf('✅ Номер <b>%s</b> додано.', $display),
            CarPlateService::ALREADY_OURS => sprintf('ℹ️ Номер <b>%s</b> уже є у вашому списку.', $display),
            CarPlateService::TAKEN => sprintf(
                "⚠️ Номер <b>%s</b> уже внесено за іншою квартирою.\n"
                    . "Якщо це ваше авто, напишіть, і ми розберемося:\n%s",
                $display,
                OsbbContacts::DEV_LINE,
            ),
            CarPlateService::FULL => sprintf('⚠️ У вашої квартири вже %d номерів — це максимум.', CarPlate::MAX_PER_ACCOUNT),
            default => '',
        };

        $this->menu->list($bot, $account, $notice);
    }

    private function account(): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }
}
