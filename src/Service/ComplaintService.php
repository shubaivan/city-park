<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Complaint;
use App\Entity\TelegramUser;
use App\Repository\ComplaintRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Input\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The house's problem register: who may file, who may move a status, and the photo
 * plumbing behind both.
 *
 * Filing is open to everyone the ОСББ recognises — see the Complaint class comment for
 * why neither `is_active` nor `isNonResidential()` is consulted. Moving a status is the
 * opposite: only the head of the ОСББ, because "виконано" is a statement about what the
 * ОСББ did, and a register anyone can mark done records nothing.
 *
 * Managers are configured as Telegram ids in `.env.local` (COMPLAINT_MANAGER_TELEGRAM_IDS),
 * the same shape as RESIDENT_CHAT_ID: the set is one or two people and changes about never,
 * so a column and an admin checkbox would be machinery for nothing.
 */
class ComplaintService
{
    public const PHOTO_DIR = 'uploads/complaint-photos';

    public const PAGE_SIZE = 8;

    public function __construct(
        private ComplaintRepository $complaints,
        private ImageStore $images,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private Nutgram $bot,
        private ResidentChatService $residentChat,
        private string $managerIds = '',
    ) {}

    /**
     * Anyone with a confirmed особовий рахунок. The register is house business, so an
     * unlinked visitor is out — but a debtor, a photo-blocked resident and the owner of a
     * parking space or a storage room are all in.
     */
    public function mayFile(?Account $account): bool
    {
        return $account instanceof Account;
    }

    public function isManager(?TelegramUser $user): bool
    {
        $id = $user?->getTelegramId();

        if ($id === null || $id === '') {
            return false;
        }

        return in_array((string)$id, $this->managerTelegramIds(), true);
    }

    /** @return string[] */
    public function managerTelegramIds(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->managerIds),
        )));
    }

    public function create(Account $account, ?TelegramUser $author, string $text): Complaint
    {
        $complaint = (new Complaint())
            ->setAccount($account)
            ->setAuthor($author)
            ->setText($this->trimText($text));

        $this->em->persist($complaint);
        $this->em->flush();

        $this->logger->info('complaint filed', [
            'complaint_id' => $complaint->getId(),
            'account_id' => $account->getId(),
            'apartment' => $account->getApartmentNumber(),
        ]);

        return $complaint;
    }

    /**
     * Move a complaint, then tell the two audiences that care.
     *
     * Both notifications live here rather than in the bot handler because the status can
     * also be moved from /admin/complaints — and when it was only wired into the handler,
     * a status changed from the admin panel told nobody at all.
     *
     * $actor is the TelegramUser doing it, when there is one: they are looking at the card
     * that already shows the new status, so they do not need a message about their own tap.
     */
    public function changeStatus(
        Complaint $complaint,
        string $status,
        string $by,
        ?TelegramUser $actor = null,
    ): void {
        $from = $complaint->getStatus();

        if ($from === $status) {
            return;
        }

        $complaint->setStatus($status, $by);
        $this->em->flush();

        $this->logger->info('complaint status changed', [
            'complaint_id' => $complaint->getId(),
            'from' => $from,
            'to' => $status,
            'by' => $by,
        ]);

        $this->notifyAuthor($complaint, $from, $actor);
        $this->announceToChat($complaint, $from);
    }

    /**
     * The person who reported it hears about it without having to come back and check.
     * That is the difference between a register and the chat it replaces.
     */
    private function notifyAuthor(Complaint $complaint, string $from, ?TelegramUser $actor): void
    {
        $author = $complaint->getAuthor();
        $chatId = $author?->getChatId();

        if ($chatId === null || $chatId === '') {
            return;
        }

        if ($actor instanceof TelegramUser && $actor->getId() === $author->getId()) {
            return;
        }

        // The transition, not just the new state: "🔧 В роботі" alone does not say whether
        // it changed a minute ago or has read that way for a week, and movement is the
        // whole reason the message is being sent.
        $text = sprintf(
            "🔧 <b>Ваша заявка №%d</b>\n\n%s\n\n%s → <b>%s</b>",
            $complaint->getId(),
            htmlspecialchars($complaint->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $this->statusLabel($from),
            $this->statusLabel($complaint->getStatus()),
        );

        if ($complaint->getResolution() !== null && $complaint->getResolution() !== '') {
            $text .= "\n\n💬 <i>" . htmlspecialchars($complaint->getResolution(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</i>';
        }

        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: (int)$chatId,
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('🔧 Відкрити заявку', callback_data: 'cmp:view:' . $complaint->getId()),
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('complaint status notify failed', [
                'complaint_id' => $complaint->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * And the house hears about it in the chat.
     *
     * A repair nobody is told about is, from the outside, indistinguishable from no repair
     * — which is how the ОСББ ends up doing the work and still being asked when somebody
     * will finally fix the lift. No apartment number: the register is public inside the
     * bot, but a status update does not need to name the neighbour who reported it.
     */
    private function announceToChat(Complaint $complaint, string $from): void
    {
        if (!$this->residentChat->isConfigured()) {
            return;
        }

        $text = sprintf(
            "%s <b>Заявка №%d</b>\n\n%s\n\n%s → <b>%s</b>",
            $complaint->isDone() ? '✅' : '🔧',
            $complaint->getId(),
            htmlspecialchars($complaint->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $this->statusLabel($from),
            $this->statusLabel($complaint->getStatus()),
        );

        if ($complaint->getResolution() !== null && $complaint->getResolution() !== '') {
            $text .= "\n\n💬 <i>" . htmlspecialchars($complaint->getResolution(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</i>';
        }

        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: (int)$this->residentChat->chatId(),
                parse_mode: ParseMode::HTML,
                disable_notification: true,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('complaint chat announcement failed', [
                'complaint_id' => $complaint->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function trimText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, Complaint::TEXT_MAX);
    }

    /**
     * Short label for the list button. The register's list is buttons, not a wall of text
     * — the same call as the rental noticeboard, for the same reason: a screen of
     * descriptions pushes the buttons below the fold.
     */
    public function label(Complaint $complaint): string
    {
        $text = $complaint->getText();

        if (mb_strlen($text) > Complaint::LABEL_MAX) {
            $text = mb_substr($text, 0, Complaint::LABEL_MAX - 1) . '…';
        }

        return $text;
    }

    /**
     * Issue (or refresh) the photo-upload link. Regenerated on every request, so a link
     * forwarded yesterday stops working as soon as a new one is asked for.
     */
    public function issuePhotoToken(Complaint $complaint): string
    {
        $token = bin2hex(random_bytes(16));

        $complaint->setPhotoToken(
            $token,
            new \DateTimeImmutable(sprintf('+%d hours', Complaint::PHOTO_TOKEN_TTL_HOURS)),
        );
        $this->em->flush();

        return $token;
    }

    public function savePromptMessageId(Complaint $complaint): void
    {
        $this->em->flush();
    }

    public function findByToken(?string $token): ?Complaint
    {
        if ($token === null || $token === '') {
            return null;
        }

        $complaint = $this->complaints->findByToken($token);

        if (!$complaint instanceof Complaint) {
            return null;
        }

        $expires = $complaint->getPhotoTokenExpiresAt();

        if (!$expires instanceof \DateTimeImmutable || $expires < new \DateTimeImmutable()) {
            return null;
        }

        return $complaint;
    }

    public function storePhoto(Complaint $complaint, UploadedFile $file, ?string &$error = null): ?string
    {
        if (count($complaint->getPhotos()) >= Complaint::PHOTOS_MAX) {
            $error = 'Більше ' . Complaint::PHOTOS_MAX . ' фото не можна.';

            return null;
        }

        $path = $this->images->store($file, self::PHOTO_DIR, $error);

        if ($path === null) {
            return null;
        }

        $complaint->setPhotos([...$complaint->getPhotos(), $path]);
        $this->em->flush();

        $this->logger->info('complaint photo stored', [
            'complaint_id' => $complaint->getId(),
            'path' => $path,
        ]);

        return $path;
    }

    public function removePhoto(Complaint $complaint, string $publicPath): void
    {
        $complaint->setPhotos(array_filter(
            $complaint->getPhotos(),
            static fn (string $p): bool => $p !== $publicPath,
        ));
        $this->em->flush();

        $this->images->delete($publicPath, self::PHOTO_DIR);
    }

    /**
     * The author withdrawing their own report.
     *
     * Allowed at any status, deliberately. The obvious alternative — letting them delete
     * only while it is still 🆕 — protects a record the ОСББ has started working on, but
     * the common reasons to withdraw (a typo, a duplicate, the thing fixed itself) do not
     * politely stop happening the moment Людмила taps «в роботі», and a resident who
     * cannot remove their own mistaken entry just files a second one saying "ignore the
     * previous". Retention would delete it within the month anyway.
     *
     * Photos go with it: nothing else references them, and orphaned files under
     * public/uploads are how a disk fills up quietly.
     */
    public function delete(Complaint $complaint): void
    {
        $id = $complaint->getId();

        foreach ($complaint->getPhotos() as $path) {
            $this->images->delete($path, self::PHOTO_DIR);
        }

        $this->em->remove($complaint);
        $this->em->flush();

        $this->logger->info('complaint deleted by author', ['complaint_id' => $id]);
    }

    public function updateText(Complaint $complaint, string $text): void
    {
        $complaint->setText($this->trimText($text));
        $this->em->flush();

        $this->logger->info('complaint text edited', ['complaint_id' => $complaint->getId()]);
    }

    public function burnPhotoToken(Complaint $complaint): void
    {
        $complaint->setPhotoToken(null, null);
        $this->em->flush();
    }

    /**
     * Rewrite the «📷 Фото до заявки» message into a confirmation.
     *
     * Called on every successful upload, not only when Готово is pressed: the Web App
     * gives the server no "closed" event, and people dismiss it with the ✕ at least as
     * often. Editing the prompt in place means whichever way they leave, the message they
     * come back to already says the photo arrived — no second message, nothing to scroll.
     *
     * A text message cannot be edited into a photo, so this stays text and the picture
     * lives on the card the buttons lead to.
     *
     * Never throws: the photo is already saved, and a failed edit must not turn a
     * successful upload into an error on the page.
     */
    public function confirmPhotoOnPrompt(Complaint $complaint): void
    {
        $messageId = $complaint->getPhotoPromptMessageId();
        $chatId = $complaint->getAuthor()?->getChatId();

        if ($messageId === null || $chatId === null || $chatId === '') {
            return;
        }

        $count = count($complaint->getPhotos());

        try {
            $this->bot->editMessageText(
                text: sprintf(
                    "✅ <b>Дякуємо, фото додано до заявки №%d</b>\n\n%s\n\n"
                        . "Фото: <b>%d з %d</b>.\n\n"
                        . '<i>Можна додати ще — просто відкрийте сторінку знову.</i>',
                    $complaint->getId(),
                    // The full text, not label(): that truncates to 40 characters because it
                    // has to fit on a button, and there is no button here.
                    htmlspecialchars($complaint->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $count,
                    Complaint::PHOTOS_MAX,
                ),
                chat_id: (int)$chatId,
                message_id: $messageId,
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make(
                        '🔧 Відкрити заявку',
                        callback_data: 'cmp:view:' . $complaint->getId(),
                    ))
                    ->addRow(
                        InlineKeyboardButton::make('📌 Мої заявки', callback_data: 'cmp:my:1'),
                        InlineKeyboardButton::make('🏠 На головну', callback_data: 'main-menu'),
                    ),
            );
        } catch (\Throwable $e) {
            // Most often "message is not modified" on the second photo of a batch.
            $this->logger->info('complaint photo prompt edit skipped', [
                'complaint_id' => $complaint->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Push the complaint into the author's chat as a picture when they finish.
     *
     * The prompt above has already been rewritten, so this is the richer version — the
     * photo itself — and only fires for people who actually press Готово.
     *
     * Never throws: the photos are already saved, and a failed notification must not turn
     * a successful upload into an error on the page.
     */
    public function notifyPhotosUpdated(Complaint $complaint): void
    {
        $chatId = $complaint->getAuthor()?->getChatId();

        if ($chatId === null || $chatId === '') {
            return;
        }

        $caption = sprintf(
            "📷 <b>Фото додано до заявки №%d</b>\n\n%s\n\nСтатус: <b>%s</b>",
            $complaint->getId(),
            htmlspecialchars($complaint->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $this->statusLabel($complaint->getStatus()),
        );

        $markup = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                '🔧 Відкрити заявку',
                callback_data: 'cmp:view:' . $complaint->getId(),
            ))
            ->addRow(
                InlineKeyboardButton::make('⬅️ До списку заявок', callback_data: 'complaints-menu'),
                InlineKeyboardButton::make('🏠 На головну', callback_data: 'main-menu'),
            );

        $cover = $complaint->getPhotos()[0] ?? null;
        $abs = $cover !== null ? $this->images->absolutePath($cover, self::PHOTO_DIR) : null;

        try {
            $stream = $abs !== null && is_readable($abs) ? @fopen($abs, 'rb') : false;

            if ($stream !== false) {
                $this->bot->sendPhoto(
                    photo: InputFile::make($stream, basename((string)$abs)),
                    caption: $caption,
                    chat_id: (int)$chatId,
                    parse_mode: ParseMode::HTML,
                    reply_markup: $markup,
                );

                return;
            }

            $this->bot->sendMessage(
                text: $caption,
                chat_id: (int)$chatId,
                parse_mode: ParseMode::HTML,
                reply_markup: $markup,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('complaint photo notify failed', [
                'complaint_id' => $complaint->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Photos stay when a complaint is finished — the picture of the repaired thing, and
     * of what it looked like before, is the point of keeping the entry at all.
     */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            Complaint::STATUS_NEW => '🆕 Нова',
            Complaint::STATUS_IN_PROGRESS => '🔧 В роботі',
            Complaint::STATUS_DONE => '✅ Виконано',
            default => $status,
        };
    }
}
