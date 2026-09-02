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
 * "✏️ Змінити текст" — the author retyping their own report.
 *
 * Replaces the text rather than appending to it: a report that reads "не працює ліфт... ні,
 * вибачте, не ліфт, а домофон" is worse than no report, and the register is read by
 * neighbours deciding whether their problem is already known.
 *
 * The complaint id travels in the callback data and is captured on the first step; the
 * conversation is serialised between updates, so it is kept as a plain int rather than the
 * entity.
 */
class ComplaintEdit extends Conversation
{
    protected ?string $step = 'askText';

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
                    '📷 Ви надіслали фото — обробляємо його як фото альтанки, редагування заявки скасовано. '
                        . 'Фото до заявки додаються окремою кнопкою «📷 Додати фото» на самій заявці.',
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
        $data = $bot->isCallbackQuery() ? (string)($bot->callbackQuery()->data ?? '') : '';
        $this->complaintId = (int)substr($data, strlen('cmp:edit:'));

        $complaint = $this->liveComplaint();

        if (!$complaint instanceof Complaint) {
            $bot->sendMessage(
                text: '⚠️ Заявку не знайдено або вона вже не ваша.',
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            $this->end();

            return;
        }

        $bot->answerCallbackQuery();
        $bot->sendMessage(
            text: sprintf(
                "✏️ <b>Заявка №%d</b>\n\nЗараз написано:\n<i>%s</i>\n\n"
                    . 'Надішліть новий текст одним повідомленням — він замінить попередній.',
                $complaint->getId(),
                htmlspecialchars($complaint->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
            parse_mode: ParseMode::HTML,
        );

        $this->next('save');
    }

    public function save(Nutgram $bot): void
    {
        $text = $this->service->trimText((string)$bot->message()?->text);

        if ($text === '') {
            $bot->sendMessage(text: 'Напишіть, будь ласка, новий текст заявки.');
            $this->next('save');

            return;
        }

        $complaint = $this->liveComplaint();

        if (!$complaint instanceof Complaint) {
            $bot->sendMessage(text: '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        $this->service->updateText($complaint, $text);

        $bot->sendMessage(
            text: sprintf(
                "✅ <b>Заявку №%d оновлено</b>\n\n%s",
                $complaint->getId(),
                htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    '⬅️ До заявки',
                    callback_data: 'cmp:view:' . $complaint->getId(),
                ))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    /**
     * The complaint, but only if the person editing still owns it.
     */
    private function liveComplaint(): ?Complaint
    {
        $complaint = $this->complaintId !== null ? $this->complaints->find($this->complaintId) : null;

        if (!$complaint instanceof Complaint) {
            return null;
        }

        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        return $account !== null && $complaint->getAccount()?->getId() === $account->getId()
            ? $complaint
            : null;
    }
}
