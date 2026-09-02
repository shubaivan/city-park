<?php

namespace App\Telegram\Complaint\Command;

use App\Entity\Account;
use App\Entity\Complaint;
use App\Service\ComplaintService;
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
 * "➕ Повідомити про проблему" — one step: describe it.
 *
 * One step on purpose. The person filing this is standing in front of a lift that does
 * not work; every extra question is a reason to close the bot and write in the chat
 * instead, which is exactly the behaviour the register exists to replace. Photos are
 * offered *after* the complaint is saved, so a resident who gives up at that point has
 * still reported the problem.
 */
class ComplaintCreate extends Conversation
{
    public const START_CALLBACK = 'cmp:new';

    protected ?string $step = 'askText';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private ComplaintService $service,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /**
     * The guard every multi-step conversation in this bot must carry: Nutgram routes EVERY
     * update from a user with a live conversation here, a pavilion photo included. Without
     * it the photo is swallowed and the resident is blocked for evidence they did send.
     *
     * It matters more than usual here, because this conversation is *about* photographing
     * something broken — the temptation to just send a picture is built into the moment.
     */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if ($bot->message()?->photo) {
            // Must never throw: an exception answers /hook with 500 and Telegram retries
            // the same photo for an hour.
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — обробляємо його як фото альтанки, створення заявки скасовано. '
                        . 'Щоб описати проблему, відкрийте «🔧 Заявки» ще раз: фото до заявки додаються '
                        . 'окремою кнопкою вже після того, як ви її подали.',
                    keptNotice: '📷 Фото поки нікуди не прикріплено — опис заявки не втрачено. '
                        . 'Допишіть, будь ласка, текстом, що саме сталося, а фото додасте кнопкою '
                        . '«📷 Додати фото» вже на готовій заявці.',
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

    public function askText(Nutgram $bot): void
    {
        $account = $this->currentAccount();

        if (!$this->service->mayFile($account)) {
            $bot->sendMessage(
                text: "🔧 <b>Заявки</b>\n\n"
                    . "Щоб подати заявку, ваш Telegram має бути прив'язаний до особового рахунку ОСББ.\n\n"
                    . "Натисніть /phone і поділіться номером телефону — якщо він є в реєстрі, бот "
                    . 'прив’яже вас одразу.',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            $this->end();

            return;
        }

        $bot->sendMessage(
            text: "🔧 <b>Що сталося?</b>\n\n"
                . "Опишіть проблему одним повідомленням — коротко і по суті.\n\n"
                . "<i>Наприклад: «Не працює ліфт у 3 під'їзді», «Прорвало шланг біля дитячого майданчика», "
                . "«Не відчиняються ворота в паркінг».</i>\n\n"
                . 'Фото можна буде додати одразу після цього.',
            parse_mode: ParseMode::HTML,
        );

        $this->next('save');
    }

    public function save(Nutgram $bot): void
    {
        $text = $this->service->trimText((string)$bot->message()?->text);

        if ($text === '') {
            $bot->sendMessage(
                text: 'Напишіть, будь ласка, текстом, що саме не працює.',
                parse_mode: ParseMode::HTML,
            );

            $this->next('save');

            return;
        }

        $user = $this->telegramUserService->getCurrentUser();
        $account = $this->currentAccount();

        if (!$account instanceof Account) {
            $bot->sendMessage(text: '⚠️ Не вдалося визначити ваш особовий рахунок. Спробуйте /start.');
            $this->end();

            return;
        }

        $complaint = $this->service->create($account, $user, $text);

        $bot->sendMessage(
            text: sprintf(
                "✅ <b>Заявку №%d прийнято</b>\n\n%s\n\n"
                    . "Її бачать усі мешканці та голова ОСББ. Статус змінюватиметься тут — "
                    . "повідомимо вас, щойно щось зрушить.",
                $complaint->getId(),
                htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    sprintf('📷 Додати фото (0/%d)', Complaint::PHOTOS_MAX),
                    callback_data: 'cmp:photos:' . $complaint->getId(),
                ))
                ->addRow(InlineKeyboardButton::make(
                    '⬅️ До списку заявок',
                    callback_data: ComplaintMenuCommand::MENU_CALLBACK,
                ))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    private function currentAccount(): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }
}
