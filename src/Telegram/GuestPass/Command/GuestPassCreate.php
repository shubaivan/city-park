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
 * «➕ Новий пропуск» — two questions: who is coming, and for how long.
 *
 * Short on purpose. The person doing this is standing in a hallway with a crew about to
 * arrive; every extra question is a reason to give up and tell the guard «нас чекають у
 * 85-й», which is the thing this feature exists to replace. So the second question is one
 * tap, «📅 Сьогодні» first — the default, and what every pass was until 18.11.2026 — then
 * «Сьогодні й завтра», «3 дні», «Тиждень», each with the date it ends on (Иван, 18.11.2026: a crew
 * on a five-day job should not need the flat to switch the pass on every morning).
 *
 * The pass is created only once the length is chosen. Its buttons are `passnew:days:<n>`,
 * deliberately outside every pattern in config/telegram.php: a callback that matches a
 * registered handler escapes the conversation in Nutgram, and the pass would never be made.
 * GuestPassWiringTest pins that.
 */
class GuestPassCreate extends Conversation
{
    public const START_CALLBACK = 'pass:new';

    /** The length buttons, answered inside this conversation: passnew:days:<days>. */
    public const DAYS_PREFIX = 'passnew:days:';

    protected ?string $step = 'askLabel';

    /** What the resident typed, kept while they pick the length. */
    public ?string $label = null;

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
                        "👷 У вашої квартири вже %s — це максимум.\n\n"
                            . "Пропуски, які не вмикали й не сканували понад %d днів, не рахуються.\n"
                            . 'Скасуйте зайвий у списку «👷 Пропуски», і зможете зробити новий.',
                        GuestPassService::livePasses($this->passes->countedFor($account)),
                        GuestPass::DORMANT_AFTER_DAYS,
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
                . "Напишіть одним рядком номер авто і хто це — так, як скажете охоронцю.\n\n"
                . "<i>Наприклад: AA1234BB · Бригада, ремонт</i>\n\n"
                . "До 60 символів — номер авто краще на початку.\n"
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

        $this->label = mb_substr($label, 0, GuestPass::MAX_LABEL);
        $this->askDays($bot);
        $this->next('saveDays');
    }

    /** «На скільки днів?» — one tap, today first. */
    public function askDays(Nutgram $bot): void
    {
        $markup = InlineKeyboardMarkup::make();

        foreach (GuestPassCommand::dayRows(self::DAYS_PREFIX, true) as $row) {
            $markup->addRow(...$row);
        }

        $markup->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel'));

        $bot->sendMessage(
            text: sprintf(
                "📅 <b>На скільки днів пропуск?</b>\n\n👷 %s\n\n"
                    . "Пропуск почне діяти одразу і працюватиме до 23:59 останнього дня — "
                    . "найдовше тиждень.\n"
                    . '<i>Вимкнути раніше чи скасувати його можна будь-коли на картці пропуску.</i>',
                htmlspecialchars((string)$this->label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: $markup,
        );
    }

    public function saveDays(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? (string)($bot->callbackQuery()->data ?? '') : '';

        if (!$bot->isCallbackQuery()) {
            // Typed instead of tapped: the question stands, the label is kept.
            $bot->sendMessage(text: 'Оберіть, будь ласка, одну з кнопок нижче 👇');
            $this->askDays($bot);

            return;
        }

        $bot->answerCallbackQuery();

        if (!str_starts_with($data, self::DAYS_PREFIX)) {
            // «⬅️ Скасувати», or a stale button from an older message.
            $this->end();
            ($this->menu)($bot);

            return;
        }

        $days = (int)substr($data, strlen(self::DAYS_PREFIX));
        $label = (string)$this->label;
        $account = $this->account();

        $this->end();

        $pass = $account === null || $label === ''
            ? null
            : $this->passes->create($account, $this->telegramUserService->getCurrentUser(), $label, $days);

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
