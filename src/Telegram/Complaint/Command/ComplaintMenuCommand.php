<?php

namespace App\Telegram\Complaint\Command;

use App\Entity\Account;
use App\Entity\Complaint;
use App\Entity\TelegramUser;
use App\Repository\ComplaintRepository;
use App\Service\ComplaintService;
use App\Service\ImageStore;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * «🔧 Заявки» — the house's problem register.
 *
 * Shaped after the rental noticeboard, deliberately: the list is an index of buttons, one
 * per complaint, and the text, photos and controls live on the card behind it. Rendering
 * every description in the list means scrolling a screen of text to reach the buttons
 * underneath it — that was learned once already and is not worth learning twice.
 *
 * Open complaints sort above finished ones. A resident opening this is usually checking
 * whether the lift has already been reported, and the answer has to be at the top.
 */
class ComplaintMenuCommand
{
    public const MENU_CALLBACK = 'complaints-menu';

    private const NOOP_CALLBACK = 'cmp:noop';

    /**
     * The «⬅️ Скасувати» button inside ComplaintReply / ComplaintHold.
     *
     * Those conversations swallow it themselves — Nutgram routes every update from a user
     * with a live conversation into it — so this handler only ever sees the tap on a
     * *stale* one, after the conversation cache has expired. Landing on the list is the
     * right answer to that; an unhandled callback is a button that spins forever.
     */
    public const CANCEL_CALLBACK = 'cmp:cancel';

    /** How many comments the thread shows before it starts hiding the oldest. */
    private const THREAD_PAGE = 10;

    /** Telegram's hard message ceiling is 4096; leave room for the keyboard-less tail. */
    private const THREAD_CHARS = 3500;

    public function __construct(
        private ComplaintRepository $complaints,
        private ComplaintService $service,
        private TelegramUserService $telegramUserService,
        private ImageStore $images,
        private LoggerInterface $logger,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? ($bot->callbackQuery()->data ?? '') : '';

        if ($data === self::NOOP_CALLBACK) {
            $bot->answerCallbackQuery();

            return;
        }

        if (str_starts_with($data, 'cmp:view:')) {
            $this->renderCard($bot, (int)substr($data, strlen('cmp:view:')));

            return;
        }

        if (str_starts_with($data, 'cmp:pic:')) {
            [$id, $index] = array_pad(explode(':', substr($data, strlen('cmp:pic:'))), 2, '0');
            $this->showPhoto($bot, (int)$id, (int)$index);

            return;
        }

        if ($data === self::CANCEL_CALLBACK) {
            $bot->answerCallbackQuery();
            $this->renderMenu($bot, edit: true);

            return;
        }

        if (str_starts_with($data, 'cmp:talk:')) {
            $this->renderThread($bot, (int)substr($data, strlen('cmp:talk:')));

            return;
        }

        if (str_starts_with($data, 'cmp:photos:')) {
            $this->photoLink($bot, (int)substr($data, strlen('cmp:photos:')));

            return;
        }

        if (str_starts_with($data, 'cmp:del:')) {
            $this->confirmDelete($bot, (int)substr($data, strlen('cmp:del:')));

            return;
        }

        if (str_starts_with($data, 'cmp:delok:')) {
            $this->delete($bot, (int)substr($data, strlen('cmp:delok:')));

            return;
        }

        if (str_starts_with($data, 'cmp:status:')) {
            [$id, $status] = array_pad(explode(':', substr($data, strlen('cmp:status:'))), 2, '');
            $this->changeStatus($bot, (int)$id, (string)$status);

            return;
        }

        if (str_starts_with($data, 'cmp:page:')) {
            $this->renderMenu($bot, edit: true, page: (int)substr($data, strlen('cmp:page:')));

            return;
        }

        if (str_starts_with($data, 'cmp:my:')) {
            $this->renderMenu($bot, edit: true, page: (int)substr($data, strlen('cmp:my:')), mineOnly: true);

            return;
        }

        $this->renderMenu($bot, edit: $bot->isCallbackQuery());
    }

    private function renderMenu(
        Nutgram $bot,
        bool $edit,
        ?string $notice = null,
        int $page = 1,
        bool $mineOnly = false,
    ): void {
        $account = $this->currentAccount($bot);

        // "Мої" without an account would silently mean "everything" — fall back to the
        // full list rather than show a personal view that is not personal.
        $mineOnly = $mineOnly && $account instanceof Account;
        $filter = $mineOnly ? $account : null;

        $total = $this->complaints->countAll($filter);
        $open = $this->complaints->countOpen($filter);

        $page = max(1, $page);
        $offset = ($page - 1) * ComplaintService::PAGE_SIZE;
        $items = $this->complaints->findForList(ComplaintService::PAGE_SIZE, $offset, $filter);

        $lines = [];

        if ($notice !== null) {
            $lines[] = $notice;
            $lines[] = '';
        }

        $lines[] = $mineOnly ? '📌 <b>Мої заявки</b>' : '🔧 <b>Заявки та скарги</b>';
        $lines[] = $mineOnly
            ? '<i>Те, про що повідомили ви.</i>'
            : '<i>Що в будинку зламалось і що з цим робиться.</i>';
        $lines[] = '';

        if ($total === 0) {
            $lines[] = $mineOnly
                ? 'Ви ще не подавали заявок.'
                : 'Поки що жодної заявки. Якщо щось не працює — напишіть, і це побачать усі мешканці та голова ОСББ.';
        } else {
            $lines[] = sprintf('Відкритих: <b>%d</b> · усього: %d', $open, $total);
            $lines[] = '';
            $lines[] = '<i>Натисніть заявку, щоб побачити подробиці, фото і статус.</i>';
        }

        $markup = InlineKeyboardMarkup::make();

        foreach ($items as $complaint) {
            $markup->addRow(InlineKeyboardButton::make(
                $this->listLabel($complaint, $account),
                callback_data: 'cmp:view:' . $complaint->getId(),
            ));
        }

        $this->addPagination($markup, $page, $total, $mineOnly);

        // The toggle only appears for someone who has an account to filter by, and only
        // once they have actually filed something — an empty "Мої" is a dead end.
        if ($account instanceof Account && ($mineOnly || $this->complaints->countAll($account) > 0)) {
            $markup->addRow(InlineKeyboardButton::make(
                $mineOnly ? '📋 Усі заявки' : '📌 Мої заявки',
                callback_data: $mineOnly ? 'cmp:page:1' : 'cmp:my:1',
            ));
        }

        // The report button sits under the list, not above it: somebody who came to check
        // whether the lift is already reported should read the list first. That is the
        // whole point of the register — one entry per problem, not four.
        if ($this->service->mayFile($account)) {
            $markup->addRow(InlineKeyboardButton::make(
                '➕ Повідомити про проблему',
                callback_data: ComplaintCreate::START_CALLBACK,
            ));
        }

        $markup->addRow(StartCommand::homeButton());

        $this->respond($bot, $edit, implode("\n", $lines), $markup);
    }

    private function addPagination(InlineKeyboardMarkup $markup, int $page, int $total, bool $mineOnly): void
    {
        $pages = (int)ceil($total / ComplaintService::PAGE_SIZE);

        if ($pages < 2) {
            return;
        }

        $prefix = $mineOnly ? 'cmp:my:' : 'cmp:page:';
        $row = [];

        if ($page > 1) {
            $row[] = InlineKeyboardButton::make('⬅️', callback_data: $prefix . ($page - 1));
        }

        $row[] = InlineKeyboardButton::make(sprintf('%d/%d', $page, $pages), callback_data: self::NOOP_CALLBACK);

        if ($page < $pages) {
            $row[] = InlineKeyboardButton::make('➡️', callback_data: $prefix . ($page + 1));
        }

        $markup->addRow(...$row);
    }

    /**
     * One button per complaint: status icon, the first words, and 📌 on your own.
     */
    private function listLabel(Complaint $complaint, ?Account $account): string
    {
        $mine = $account instanceof Account
            && $complaint->getAccount()?->getId() === $account->getId();

        return sprintf(
            '%s %s%s%s',
            $this->statusIcon($complaint),
            $this->service->label($complaint),
            $complaint->getPhotos() !== [] ? ' 📷' : '',
            $mine ? ' 📌' : '',
        );
    }

    private function statusIcon(Complaint $complaint): string
    {
        return match ($complaint->getStatus()) {
            Complaint::STATUS_DONE => '✅',
            Complaint::STATUS_IN_PROGRESS => '🔧',
            Complaint::STATUS_ON_HOLD => '⏸',
            default => '🆕',
        };
    }

    private function renderCard(Nutgram $bot, int $complaintId, int $index = 0): void
    {
        $complaint = $this->complaints->find($complaintId);

        if (!$complaint instanceof Complaint) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Таку заявку не знайдено.');

            return;
        }

        $caption = $this->describe($complaint);
        $markup = InlineKeyboardMarkup::make();

        if ($complaint->getPhotos() !== []) {
            $index = $this->normaliseIndex($complaint, $index);
            $this->addPhotoNav($markup, $complaint, $index);
            $this->addCardControls($bot, $markup, $complaint);

            if ($this->sendPhotoCard($bot, $complaint, $index, $caption, $markup)) {
                return;
            }

            // The picture could not be sent — fall through to the text card, whose keyboard
            // must not keep arrows that would now edit a message with no media.
            $markup = InlineKeyboardMarkup::make();
        }

        $this->addCardControls($bot, $markup, $complaint);
        $this->respond($bot, edit: true, text: $caption, markup: $markup);
    }

    private function describe(Complaint $complaint): string
    {
        $account = $complaint->getAccount();
        $where = $account?->getApartmentNumber();
        $house = $account?->getHouseNumber();

        $from = match (true) {
            $where !== null && $house !== null => sprintf('буд. %s, кв. %s', $this->esc($house), $this->esc($where)),
            $where !== null => 'кв. ' . $this->esc($where),
            default => 'мешканець',
        };

        $lines = [
            sprintf('%s <b>Заявка №%d</b>', $this->statusIcon($complaint), $complaint->getId()),
            '',
            $this->esc($complaint->getText()),
            '',
            sprintf('Статус: <b>%s</b>', $this->service->statusLabel($complaint->getStatus())),
            sprintf('Подано: %s · %s', $this->kyiv($complaint->getCreatedAt()), $from),
        ];

        if ($complaint->getStatus() !== Complaint::STATUS_NEW) {
            $by = $complaint->getStatusChangedBy();
            $lines[] = sprintf(
                'Оновлено: %s%s',
                $this->kyiv($complaint->getStatusChangedAt()),
                $by !== null && $by !== '' ? ' · ' . $this->esc($by) : '',
            );
        }

        if ($complaint->getResolution() !== null && $complaint->getResolution() !== '') {
            $lines[] = '';
            // On a held complaint this line is the reason it is held — the half that keeps
            // «відкладено» from reading as "нам байдуже".
            $lines[] = ($complaint->isOnHold() ? '⏸ <i>' : '💬 <i>')
                . $this->esc($complaint->getResolution()) . '</i>';
        }

        // Who to ring, for the one person who has to. Every resident reads this register,
        // and the author's number is in the database because they gave it to the ОСББ for
        // нарахування — so it is shown to the head of the ОСББ (who already has it in
        // /admin/users) and to nobody else.
        if ($this->service->isManager($this->telegramUserService->getCurrentUser())) {
            $lines[] = '';
            $lines[] = $this->service->authorContactLine($complaint);
        }

        return implode("\n", $lines);
    }

    /**
     * «💬 Обговорення» — the official thread under one complaint.
     *
     * A separate message rather than more lines on the card, for a mechanical reason as
     * much as a design one: a card with photos is a *caption*, and Telegram caps a caption
     * at 1024 characters. A thread appended to it would render fine for two comments and
     * then silently stop sending the card at all.
     *
     * Read by anyone who opens it — that is what makes it a record instead of a private
     * chat. Written by the author and the head of the ОСББ only.
     */
    private function renderThread(Nutgram $bot, int $complaintId): void
    {
        $complaint = $this->complaints->find($complaintId);

        if (!$complaint instanceof Complaint) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Таку заявку не знайдено.');

            return;
        }

        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;
        $canComment = $this->service->mayComment($complaint, $user, $account);

        $total = $this->service->countComments($complaint);
        $shown = $this->service->thread($complaint, self::THREAD_PAGE);

        $head = [
            sprintf('💬 <b>Обговорення заявки №%d</b>', $complaint->getId()),
            '<i>' . $this->esc($this->service->label($complaint)) . '</i>',
            sprintf('Статус: <b>%s</b>', $this->service->statusLabel($complaint->getStatus())),
        ];

        $body = [];

        foreach ($shown as $comment) {
            $body[] = $this->service->renderComment($comment);
        }

        // Telegram refuses a message over 4096 characters outright, and a thread is the one
        // place in this bot where the text grows without anyone deciding to make it grow.
        // Drop from the top — the oldest lines — until it fits, and say so.
        $trimmed = 0;

        while ($body !== [] && mb_strlen(implode("\n\n", [...$head, ...$body])) > self::THREAD_CHARS) {
            array_shift($body);
            $trimmed++;
        }

        $hidden = $total - count($body);

        if ($body === []) {
            $body[] = $canComment
                ? 'Тут ще нічого не написано. Напишіть перше повідомлення — його побачать усі мешканці.'
                : 'Тут ще нічого не написано.';
        } elseif ($hidden > 0) {
            array_unshift($body, sprintf('<i>… раніші повідомлення (%d) приховані.</i>', $hidden));
        }

        $markup = InlineKeyboardMarkup::make();

        if ($canComment) {
            $markup->addRow(InlineKeyboardButton::make(
                '✍️ Написати',
                callback_data: 'cmp:say:' . $complaint->getId(),
            ));
        }

        if ($this->service->isManager($user)) {
            $url = $this->service->authorChatUrl($complaint);

            if ($url !== null) {
                $markup->addRow(InlineKeyboardButton::make('✍️ Написати автору особисто', url: $url));
            }
        }

        $markup->addRow(InlineKeyboardButton::make(
            '⬅️ До заявки',
            callback_data: 'cmp:view:' . $complaint->getId(),
        ));
        $markup->addRow(StartCommand::homeButton());

        // A card with photos is a photo message, and editMessageText cannot turn one back
        // into text — the same reason leaving a rental photo card deletes it.
        $this->respond($bot, edit: true, text: implode("\n\n", [...$head, ...$body]), markup: $markup);
    }

    /**
     * Everything under the card that is not photo navigation.
     *
     * Built in one place and used by both the initial render and every photo swap, so
     * leafing through pictures can never produce a card with a different keyboard than
     * the one that was opened.
     */
    private function addCardControls(Nutgram $bot, InlineKeyboardMarkup $markup, Complaint $complaint): void
    {
        $user = $this->telegramUserService->getCurrentUser();

        // Only the author adds photos, and only while the complaint is open: pictures are
        // evidence of the problem, and a finished entry is a record, not a working file.
        if ($complaint->isOpen() && $this->isAuthor($complaint, $user)) {
            $markup->addRow(InlineKeyboardButton::make(
                sprintf('📷 Додати фото (%d/%d)', count($complaint->getPhotos()), Complaint::PHOTOS_MAX),
                callback_data: 'cmp:photos:' . $complaint->getId(),
            ));
        }

        // The author's own entry stays theirs: a typo, a duplicate or a problem that fixed
        // itself must be fixable by the person who filed it, without going through anyone.
        if ($this->isAuthor($complaint, $user)) {
            $markup->addRow(
                InlineKeyboardButton::make('✏️ Змінити текст', callback_data: 'cmp:edit:' . $complaint->getId()),
                InlineKeyboardButton::make('🗑 Видалити', callback_data: 'cmp:del:' . $complaint->getId()),
            );
        }

        // The discussion is read by the whole house and written by two people. Hidden only
        // when it is both empty and closed to this reader — an empty thread nobody may add
        // to is a button that leads nowhere.
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;
        $canComment = $this->service->mayComment($complaint, $user, $account);
        $comments = $this->service->countComments($complaint);

        if ($comments > 0 || $canComment) {
            $markup->addRow(InlineKeyboardButton::make(
                $comments > 0 ? sprintf('💬 Обговорення (%d)', $comments) : '💬 Обговорення',
                callback_data: 'cmp:talk:' . $complaint->getId(),
            ));
        }

        // Statuses are the head of the ОСББ's alone. "Виконано" is a statement about what
        // the ОСББ did, and a register anyone can mark done records nothing.
        if ($this->service->isManager($user)) {
            $status = $complaint->getStatus();

            if ($complaint->isDone()) {
                // Reopening is one button, not the whole ladder: a finished entry that
                // turns out unfinished goes back to work, and the rest follows from there.
                $markup->addRow(InlineKeyboardButton::make(
                    '↩️ Повернути в роботу',
                    callback_data: sprintf('cmp:status:%d:%s', $complaint->getId(), Complaint::STATUS_IN_PROGRESS),
                ));
            } else {
                $row = [];

                if ($status !== Complaint::STATUS_IN_PROGRESS) {
                    $row[] = InlineKeyboardButton::make(
                        '🔧 В роботі',
                        callback_data: sprintf('cmp:status:%d:%s', $complaint->getId(), Complaint::STATUS_IN_PROGRESS),
                    );
                }

                // Never a plain status flip: a hold has to say what it is waiting for, so
                // the button opens the conversation that asks. See ComplaintHold.
                $row[] = InlineKeyboardButton::make(
                    $status === Complaint::STATUS_ON_HOLD ? '⏸ Змінити причину' : '⏸ Відкласти',
                    callback_data: 'cmp:hold:' . $complaint->getId(),
                );

                if ($row !== []) {
                    $markup->addRow(...$row);
                }

                $markup->addRow(InlineKeyboardButton::make(
                    '✅ Виконано',
                    callback_data: sprintf('cmp:status:%d:%s', $complaint->getId(), Complaint::STATUS_DONE),
                ));
            }

            // Straight into a private chat with whoever filed it. The thread above is the
            // record; this is for "приходьте подивіться самі" and for the questions that
            // are nobody else's business.
            $url = $this->service->authorChatUrl($complaint);

            if ($url !== null) {
                $markup->addRow(InlineKeyboardButton::make('✍️ Написати автору', url: $url));
            }
        }

        $markup->addRow(InlineKeyboardButton::make('⬅️ До списку', callback_data: self::MENU_CALLBACK));
        $markup->addRow(StartCommand::homeButton());
    }

    private function isAuthor(Complaint $complaint, ?TelegramUser $user): bool
    {
        if (!$user instanceof TelegramUser) {
            return false;
        }

        $accountId = $this->telegramUserService->resolveAccount($user)?->getId();

        return $accountId !== null && $complaint->getAccount()?->getId() === $accountId;
    }

    private function changeStatus(Nutgram $bot, int $complaintId, string $status): void
    {
        $user = $this->telegramUserService->getCurrentUser();

        if (!$this->service->isManager($user)) {
            $bot->answerCallbackQuery(text: 'Статус може змінювати лише голова ОСББ.', show_alert: true);

            return;
        }

        $complaint = $this->complaints->find($complaintId);

        if (!$complaint instanceof Complaint || !in_array($status, Complaint::STATUSES, true)) {
            $bot->answerCallbackQuery(text: '⚠️ Заявку не знайдено.', show_alert: true);

            return;
        }

        // A hold has to say what it is waiting for, and changeStatus() throws without one.
        // The card's own button never sends this — but a callback re-tapped from an old
        // message, or hand-crafted, would otherwise answer 500 and Telegram would retry it.
        if ($status === Complaint::STATUS_ON_HOLD) {
            $bot->answerCallbackQuery(
                text: 'Щоб відкласти заявку, натисніть «⏸ Відкласти» — бот запитає причину.',
                show_alert: true,
            );

            return;
        }

        // The author notice and the chat announcement now ride inside changeStatus(), so
        // that a status moved from /admin/complaints reaches the same two audiences.
        $this->service->changeStatus($complaint, $status, $this->displayName($user), actor: $user);

        $bot->answerCallbackQuery(text: $this->service->statusLabel($status));
        $this->renderCard($bot, $complaintId);
    }

    /**
     * Deleting is irreversible and takes the photos with it, so it asks first — and shows
     * the text being deleted, because the button was tapped from a list where every entry
     * looks much like the next.
     */
    private function confirmDelete(Nutgram $bot, int $complaintId): void
    {
        $complaint = $this->complaints->find($complaintId);
        $user = $this->telegramUserService->getCurrentUser();

        if (!$complaint instanceof Complaint || !$this->isAuthor($complaint, $user)) {
            $bot->answerCallbackQuery(text: 'Видалити заявку може лише той, хто її подав.', show_alert: true);

            return;
        }

        $this->respond(
            $bot,
            edit: true,
            text: sprintf(
                "🗑 <b>Видалити заявку №%d?</b>\n\n<i>%s</i>\n\n%s"
                    . 'Її більше не побачить ніхто — ні сусіди, ні голова ОСББ. Це не скасувати.',
                $complaint->getId(),
                $this->esc($complaint->getText()),
                $complaint->getPhotos() !== []
                    ? sprintf("Разом із нею зникнуть %d фото.\n\n", count($complaint->getPhotos()))
                    : '',
            ),
            markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    '🗑 Так, видалити',
                    callback_data: 'cmp:delok:' . $complaint->getId(),
                ))
                ->addRow(InlineKeyboardButton::make(
                    '⬅️ Ні, залишити',
                    callback_data: 'cmp:view:' . $complaint->getId(),
                )),
        );
    }

    private function delete(Nutgram $bot, int $complaintId): void
    {
        $complaint = $this->complaints->find($complaintId);
        $user = $this->telegramUserService->getCurrentUser();

        if (!$complaint instanceof Complaint || !$this->isAuthor($complaint, $user)) {
            $bot->answerCallbackQuery(text: 'Видалити заявку може лише той, хто її подав.', show_alert: true);

            return;
        }

        $this->service->delete($complaint);

        $bot->answerCallbackQuery(text: 'Заявку видалено.');
        $this->renderMenu($bot, edit: true, notice: sprintf('🗑 Заявку №%d видалено.', $complaintId));
    }

    private function photoLink(Nutgram $bot, int $complaintId): void
    {
        $complaint = $this->complaints->find($complaintId);
        $user = $this->telegramUserService->getCurrentUser();

        if (!$complaint instanceof Complaint || !$this->isAuthor($complaint, $user)) {
            $bot->answerCallbackQuery(text: 'Фото додає той, хто подав заявку.', show_alert: true);

            return;
        }

        $token = $this->service->issuePhotoToken($complaint);
        $url = $this->urlGenerator->generate(
            'complaint_photo_page',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->respond(
            $bot,
            edit: true,
            text: "📷 <b>Фото до заявки</b>\n\n"
                . 'Відкрийте сторінку і виберіть до ' . Complaint::PHOTOS_MAX
                . " фото з галереї телефону — вони одразу додадуться до заявки.\n\n"
                . '<i>Посилання діє ' . Complaint::PHOTO_TOKEN_TTL_HOURS . ' години і лише для цієї заявки. '
                . "Фото альтанки сюди не вантажте — їх, як і раніше, надсилайте прямо в бот.</i>",
            // web_app rather than a plain url: the page then runs inside Telegram and closes
            // itself when the resident is done, dropping them back in the chat instead of
            // leaving a browser tab open behind them.
            markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('📷 Відкрити сторінку', web_app: WebAppInfo::make($url)))
                ->addRow(InlineKeyboardButton::make('⬅️ До заявки', callback_data: 'cmp:view:' . $complaint->getId()))
                ->addRow(StartCommand::homeButton()),
        );

        // Remember which message carries the link, so the upload can rewrite it into a
        // confirmation. Captured after respond() because that is what put it on screen —
        // an edit reuses the current message id, a fresh send makes a new one.
        $messageId = $bot->messageId();

        if ($messageId !== null) {
            $complaint->setPhotoPromptMessageId($messageId);
            $this->service->savePromptMessageId($complaint);
        }
    }

    private function sendPhotoCard(
        Nutgram $bot,
        Complaint $complaint,
        int $index,
        string $caption,
        InlineKeyboardMarkup $markup,
    ): bool {
        $abs = $this->photoPath($complaint, $index);

        if ($abs === null) {
            return false;
        }

        $stream = @fopen($abs, 'rb');

        if ($stream === false) {
            return false;
        }

        try {
            $bot->sendPhoto(
                photo: InputFile::make($stream, basename($abs)),
                caption: $caption,
                parse_mode: ParseMode::HTML,
                reply_markup: $markup,
            );
        } catch (\Throwable $e) {
            $this->logger->error('complaint photo card failed, falling back to text', [
                'complaint_id' => $complaint->getId(),
                'path' => $abs,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        try {
            $bot->deleteMessage($bot->chatId(), $bot->messageId());
        } catch (\Throwable) {
            // Older than 48h, or already gone — harmless.
        }

        return true;
    }

    private function addPhotoNav(InlineKeyboardMarkup $markup, Complaint $complaint, int $index): void
    {
        $total = count($complaint->getPhotos());

        if ($total < 2) {
            return;
        }

        $prev = ($index - 1 + $total) % $total;
        $next = ($index + 1) % $total;

        $markup->addRow(
            InlineKeyboardButton::make('⬅️', callback_data: sprintf('cmp:pic:%d:%d', $complaint->getId(), $prev)),
            InlineKeyboardButton::make(
                sprintf('🖼 %d/%d', $index + 1, $total),
                callback_data: self::NOOP_CALLBACK,
            ),
            InlineKeyboardButton::make('➡️', callback_data: sprintf('cmp:pic:%d:%d', $complaint->getId(), $next)),
        );
    }

    private function showPhoto(Nutgram $bot, int $complaintId, int $index): void
    {
        $complaint = $this->complaints->find($complaintId);

        if (!$complaint instanceof Complaint) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Таку заявку не знайдено.');

            return;
        }

        $index = $this->normaliseIndex($complaint, $index);
        $abs = $this->photoPath($complaint, $index);
        $stream = $abs !== null ? @fopen($abs, 'rb') : false;

        if ($stream === false) {
            $bot->answerCallbackQuery(text: '⚠️ Це фото більше недоступне.');
            $this->renderCard($bot, $complaintId);

            return;
        }

        $markup = InlineKeyboardMarkup::make();
        $this->addPhotoNav($markup, $complaint, $index);
        $this->addCardControls($bot, $markup, $complaint);

        $bot->answerCallbackQuery();

        try {
            $bot->editMessageMedia(
                media: InputMediaPhoto::make(
                    media: InputFile::make($stream, basename((string)$abs)),
                    caption: $this->describe($complaint),
                    parse_mode: ParseMode::HTML,
                ),
                reply_markup: $markup,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('complaint photo swap failed, re-rendering the card', [
                'complaint_id' => $complaint->getId(),
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            $this->renderCard($bot, $complaintId, $index);
        }
    }

    private function normaliseIndex(Complaint $complaint, int $index): int
    {
        $total = count($complaint->getPhotos());

        if ($total < 1) {
            return 0;
        }

        return (($index % $total) + $total) % $total;
    }

    private function photoPath(Complaint $complaint, int $index): ?string
    {
        $path = $complaint->getPhotos()[$index] ?? null;
        $abs = $path !== null ? $this->images->absolutePath($path, ComplaintService::PHOTO_DIR) : null;

        return $abs !== null && is_readable($abs) ? $abs : null;
    }

    private function displayName(?TelegramUser $user): string
    {
        $name = trim(sprintf('%s %s', (string)$user?->getFirstName(), (string)$user?->getLastName()));

        return $name !== '' ? $name : 'голова ОСББ';
    }

    private function currentAccount(Nutgram $bot): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }

    private function kyiv(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i');
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function respond(Nutgram $bot, bool $edit, string $text, InlineKeyboardMarkup $markup): void
    {
        if ($edit) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);

                return;
            } catch (\Throwable) {
                try {
                    // A photo card cannot be edited into text — drop it rather than leave
                    // the picture hanging above the list.
                    $bot->deleteMessage($bot->chatId(), $bot->messageId());
                } catch (\Throwable) {
                    // Older than 48h, or already gone.
                }
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }
}
