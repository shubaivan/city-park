<?php

namespace App\Telegram\GuestPass\Command;

use App\Entity\GuestPass;
use App\Service\GuestPassService;
use App\Service\PhotoUploadFlow;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «➕ Новий пропуск» — one question: who is coming.
 *
 * One step on purpose. The person doing this is standing in a hallway with a crew about to
 * arrive; every extra question is a reason to give up and tell the guard «нас чекають у
 * 85-й», which is the thing this feature exists to replace. The day is not asked either —
 * a pass is switched on for today the moment it is made, and switched on again tomorrow
 * with one tap.
 */
class GuestPassCreate extends Conversation
{
    public const START_CALLBACK = 'pass:new';

    protected ?string $step = 'askLabel';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private GuestPassService $passes,
        private GuestPassCommand $menu,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /**
     * The guard every multi-step conversation in this bot carries: Nutgram routes every
     * update from a user with a live conversation here, a pavilion photo included, and
     * without this the photo is swallowed and its sender blocked for evidence they did
     * send. Guarded on isIncomingPhoto(), never on $bot->message()?->photo — on a callback
     * query that is the message the button hangs on, which may itself be a photo.
     */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if (PhotoUploadFlow::isIncomingPhoto($bot)) {
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — обробляємо його, створення пропуску скасовано. '
                        . 'Щоб зробити пропуск, відкрийте «👷 Пропуски» ще раз.',
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

    public function askLabel(Nutgram $bot): void
    {
        $bot->isCallbackQuery() && $bot->answerCallbackQuery();

        $account = $this->account();

        if (!$this->passes->mayCreate($account)) {
            $bot->sendMessage(
                text: $account === null
                    ? "Спершу підтвердіть номер телефону: /phone"
                    : sprintf(
                        "👷 У вашої квартири вже %d діючих пропуски — це максимум.\n\n"
                            . 'Скасуйте зайвий у списку «👷 Пропуски», і зможете зробити новий.',
                        GuestPass::MAX_PER_ACCOUNT,
                    ),
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('👷 Пропуски', callback_data: GuestPassCommand::MENU_CALLBACK))
                    ->addRow(StartCommand::homeButton()),
            );
            $this->end();

            return;
        }

        $bot->sendMessage(
            text: "👷 <b>Пропуск для робітників</b>\n\n"
                . "Напишіть одним рядком, хто це — так, як скажете охоронцю.\n\n"
                . "<i>Наприклад: Бригада, ремонт · Майстер із дверей · Доставка меблів</i>\n\n"
                . 'Цей рядок побачить кожен, хто відсканує код, разом із вашою квартирою.',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
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

        $label = trim((string)$bot->message()?->text);

        if ($label === '') {
            $bot->sendMessage(text: 'Напишіть коротко, хто це — наприклад «Бригада, ремонт».');

            return;
        }

        $account = $this->account();
        $pass = $account === null ? null : $this->passes->create($account, $this->telegramUserService->getCurrentUser(), $label);

        $this->end();

        if ($pass === null) {
            $bot->sendMessage(text: '⚠️ Не вдалося створити пропуск. Спробуйте ще раз.');

            return;
        }

        $this->menu->sendCard($bot, $pass, created: true);
    }

    private function account(): ?\App\Entity\Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }
}
