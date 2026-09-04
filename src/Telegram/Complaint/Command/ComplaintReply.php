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
 * «💬 Написати» — one line added to a complaint's official discussion.
 *
 * The feature this exists for: the head of the ОСББ could read «не працюють ворота в
 * паркінг» and had no way to ask *which* gate, or to say that the part arrives on Tuesday.
 * She was doing both outside the bot, and whatever was agreed there was known to two
 * people while the register still showed «в роботі» and the house drew its own
 * conclusions.
 *
 * Written by the author and the head of the ОСББ, read by everyone — see ComplaintComment
 * for why it is not open to all 141 flats. Nothing here can be edited or deleted
 * afterwards: a discussion the parties can rewrite is not a record of anything.
 *
 * The complaint id travels in the callback data and is captured on the first step; the
 * conversation is serialised between updates, so it is kept as a plain int rather than the
 * entity.
 */
class ComplaintReply extends Conversation
{
    public const START_PREFIX = 'cmp:say:';

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
                    '📷 Ви надіслали фото — обробляємо його як фото альтанки, повідомлення в заявку не додано. '
                        . 'Фото до заявки додаються кнопкою «📷 Додати фото» на самій заявці.',
                    keptNotice: '📷 Фото в обговорення не додається. '
                        . 'Напишіть, будь ласка, текст — а фото додасте кнопкою '
                        . '«📷 Додати фото» на самій заявці.',
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
        $this->complaintId = (int)substr($data, strlen(self::START_PREFIX));

        $complaint = $this->allowedComplaint();

        if (!$complaint instanceof Complaint) {
            $bot->answerCallbackQuery(
                text: 'В обговоренні заявки пишуть той, хто її подав, і голова ОСББ.',
                show_alert: true,
            );

            $this->end();

            return;
        }

        $bot->answerCallbackQuery();
        $bot->sendMessage(
            text: sprintf(
                "💬 <b>Обговорення заявки №%d</b>\n\n<i>%s</i>\n\n"
                    . "Напишіть повідомлення одним текстом.\n\n"
                    . '<i>Його побачать усі мешканці на цій заявці — це офіційне обговорення, '
                    . 'а не особисте листування. Видалити написане не можна.</i>',
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
        // Any button press while the conversation is live lands here, not on its handler:
        // Nutgram routes every update from this user into the conversation. Treat it as
        // "передумав" and get out of the way, rather than answering a tapped button with
        // "напишіть, будь ласка, текст".
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery(text: 'Скасовано.');
            $this->finish($bot, 'Нічого не додано в обговорення.');

            return;
        }

        $text = $this->service->trimComment((string)$bot->message()?->text);

        if ($text === '') {
            $bot->sendMessage(text: 'Напишіть, будь ласка, повідомлення текстом.');
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

        $this->service->comment(
            $complaint,
            $user,
            $text,
            official: $this->service->isManager($user),
        );

        $this->finish($bot, '✅ Додано в обговорення — його бачать усі мешканці.');
    }

    private function finish(Nutgram $bot, string $notice): void
    {
        $id = (int)$this->complaintId;

        $bot->sendMessage(
            text: $notice,
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('💬 Обговорення', callback_data: 'cmp:talk:' . $id))
                ->addRow(InlineKeyboardButton::make('⬅️ До заявки', callback_data: 'cmp:view:' . $id))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    /** The complaint, but only if this person may write under it. */
    private function allowedComplaint(): ?Complaint
    {
        $complaint = $this->complaintId !== null ? $this->complaints->find($this->complaintId) : null;

        if (!$complaint instanceof Complaint) {
            return null;
        }

        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        return $this->service->mayComment($complaint, $user, $account) ? $complaint : null;
    }
}
