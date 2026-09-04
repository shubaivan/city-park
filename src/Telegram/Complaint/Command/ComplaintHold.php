<?php

namespace App\Telegram\Complaint\Command;

use App\Entity\Complaint;
use App\Repository\ComplaintRepository;
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
 * «⏸ Відкласти» — the one status change that asks a question first.
 *
 * Most house problems spend their life in a state the register had no word for: known,
 * agreed, and waiting on a part, a contractor or the money. Held under «в роботі» it looks
 * to a resident reading it a second week running exactly like nothing happening — which is
 * the conclusion the whole register exists to prevent. Called «відкладено» with no reason
 * given, it looks like the ОСББ shrugging in public, which is worse.
 *
 * So the reason is mandatory, and enforced twice: here, where the button leads to a
 * question instead of a status flip, and in ComplaintService::changeStatus(), which
 * refuses a hold with an empty note no matter who calls it.
 */
class ComplaintHold extends Conversation
{
    public const START_PREFIX = 'cmp:hold:';

    protected ?string $step = 'askReason';

    public ?int $complaintId = null;

    public function __construct(
        private ComplaintRepository $complaints,
        private ComplaintService $service,
        private TelegramUserService $telegramUserService,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /**
     * The guard every multi-step conversation in this bot must carry — Nutgram routes every
     * update from a user with a live conversation here, a pavilion photo included.
     */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if ($bot->message()?->photo) {
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — обробляємо його як фото альтанки, статус заявки не змінено.',
                    keptNotice: '📷 Фото сюди не додається. Напишіть, будь ласка, текстом, '
                        . 'чого чекає ця заявка.',
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

    public function askReason(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? (string)($bot->callbackQuery()->data ?? '') : '';
        $this->complaintId = (int)substr($data, strlen(self::START_PREFIX));

        $complaint = $this->allowedComplaint();

        if (!$complaint instanceof Complaint) {
            $bot->answerCallbackQuery(text: 'Статус може змінювати лише голова ОСББ.', show_alert: true);
            $this->end();

            return;
        }

        $bot->answerCallbackQuery();
        $bot->sendMessage(
            text: sprintf(
                "⏸ <b>Відкласти заявку №%d</b>\n\n<i>%s</i>\n\n"
                    . "Напишіть одним повідомленням, чого вона чекає.\n\n"
                    . "Наприклад: «чекаємо насос, буде за два тижні», «підрядник приїде після 15-го», "
                    . "«внесено в кошторис на жовтень».\n\n"
                    . '<i>Причину побачать автор заявки і всі мешканці. Без неї «відкладено» '
                    . 'читається як «нам байдуже» — тому вона обов\'язкова.</i>',
                $complaint->getId(),
                htmlspecialchars($complaint->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                '⬅️ Скасувати',
                callback_data: ComplaintMenuCommand::CANCEL_CALLBACK,
            )),
        );

        $this->next('save');
    }

    public function save(Nutgram $bot): void
    {
        // Any button press while the conversation is live lands here, not on its handler.
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery(text: 'Скасовано.');
            $this->finish($bot, 'Статус заявки не змінено.');

            return;
        }

        $reason = $this->service->trimComment((string)$bot->message()?->text);

        if ($reason === '') {
            $bot->sendMessage(text: 'Напишіть, будь ласка, чого чекає заявка — без причини відкласти не можна.');
            $this->next('save');

            return;
        }

        $complaint = $this->allowedComplaint();

        if (!$complaint instanceof Complaint) {
            $bot->sendMessage(text: '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        $user = $this->telegramUserService->getCurrentUser();

        $this->service->changeStatus(
            $complaint,
            Complaint::STATUS_ON_HOLD,
            $this->service->authorName($user) ?: 'ОСББ',
            actor: $user,
            note: $reason,
        );

        $this->finish($bot, sprintf(
            "⏸ <b>Заявку №%d відкладено</b>\n\n%s",
            (int)$this->complaintId,
            htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ));
    }

    private function finish(Nutgram $bot, string $notice): void
    {
        $id = (int)$this->complaintId;

        $bot->sendMessage(
            text: $notice,
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('⬅️ До заявки', callback_data: 'cmp:view:' . $id))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    /** The complaint, but only for the head of the ОСББ. */
    private function allowedComplaint(): ?Complaint
    {
        $complaint = $this->complaintId !== null ? $this->complaints->find($this->complaintId) : null;

        if (!$complaint instanceof Complaint) {
            return null;
        }

        return $this->service->isManager($this->telegramUserService->getCurrentUser())
            ? $complaint
            : null;
    }
}
