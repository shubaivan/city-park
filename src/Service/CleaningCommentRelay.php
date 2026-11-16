<?php

namespace App\Service;

use App\Entity\CleaningComment;
use App\Entity\ServiceStaff;
use App\Message\CleaningCommentFlushMessage;
use App\Repository\CleaningCommentRepository;
use App\Repository\ServiceStaffRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\MessageEntityType;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\Message\MessageEntity;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * What the house writes in «🧹 Прибирання» reaches the cleaning crew (Иван, 16.11.2026).
 *
 * The crew is not in the residents' chat, so the bot carries every comment from the topic
 * into their private chat with it — **anonymously**: no name, @username, flat or phone, not
 * even stored. Only the words (with @mentions, links to people and phone numbers cut out)
 * and the photos travel; a reply to a photo report says «до звіту від <дата, час>».
 *
 * Skipped: bots (the reports themselves are the bot's posts), service messages, the crew's
 * own messages, anything left empty after the cut. Posts by the board as the group itself
 * (anonymous admin) do go — «правління» is part of who comments.
 *
 * A burst is batched: every comment is written down and a delayed job sends whatever has
 * gathered once the topic has been quiet for a few seconds (at most a minute after the
 * first), as one message per worker instead of one per comment.
 *
 * It is one-way. The crew answers by cleaning; a reply to a relayed message gets
 * «відповісти тут не можна» instead of silence.
 */
class CleaningCommentRelay
{
    /** The job for a comment runs this long after it; any newer comment pushes the send back. */
    public const QUIET_MS = 10000;

    /** A topic that never goes quiet still reaches the crew once this much has waited. */
    public const MAX_WAIT_S = 60;

    private const TEXT_LIMIT = 4000;
    private const CAPTION_LIMIT = 1024;

    public const SINGLE_HEAD = '💬 Коментар у «Прибиранні»';
    public const BATCH_HEAD = '💬 Нові коментарі у «Прибиранні»';
    public const PHOTOS_HEAD = '📷 Фото з коментарів у «Прибиранні»';
    public const REPLY_REFUSED = 'Відповісти тут не можна — зверніться, будь ласка, до правління.';

    public function __construct(
        private CleaningCommentRepository $comments,
        private ServiceStaffRepository $staff,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private Nutgram $bot,
        private LoggerInterface $chatLogger,
        private string $residentChatId = '',
        private string $topicCleaning = '',
        private int $pauseMicros = 350000,
    ) {}

    public function topic(): ?int
    {
        return ctype_digit($this->topicCleaning) && $this->topicCleaning !== '0' ? (int)$this->topicCleaning : null;
    }

    public function isConfigured(): bool
    {
        return $this->residentChatId !== '' && $this->topic() !== null;
    }

    /**
     * A message from the residents' chat. Never throws: this runs inside /hook, and a 500
     * makes Telegram retry the same message for an hour.
     */
    public function capture(?Message $message): void
    {
        try {
            $this->doCapture($message);
        } catch (\Throwable $e) {
            $this->chatLogger->error('cleaning comment capture failed', ['error' => $e->getMessage()]);
        }
    }

    private function doCapture(?Message $m): void
    {
        if ($m === null || !$this->isConfigured()
            || (string)$m->chat->id !== $this->residentChatId
            || $m->message_thread_id !== $this->topic()
            || !$this->isFromTheHouse($m)) {
            return;
        }

        $useText = $m->text !== null;
        $text = self::anonymise($useText ? $m->text : $m->caption, $useText ? $m->entities : $m->caption_entities);

        $sizes = $m->photo ?? [];
        $fileId = $sizes !== [] ? $sizes[array_key_last($sizes)]->file_id : null;

        // A video, file or voice is not carried over; a comment written under one says so.
        $otherMedia = $m->video !== null || $m->document !== null || $m->animation !== null || $m->voice !== null || $m->video_note !== null;
        if ($fileId === null && $otherMedia && $text !== '') {
            $text .= ' (+ відео/файл — його видно лише в чаті)';
        }

        if ($text === '' && $fileId === null) {
            return;
        }

        if ($this->comments->findByChatMessage($m->message_id) !== null) {
            return;
        }

        $comment = new CleaningComment(
            $m->message_id,
            $text,
            $fileId,
            $m->media_group_id,
            $this->reportTime($m),
            new \DateTimeImmutable('@' . $m->date),
        );

        try {
            $this->em->persist($comment);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return; // a webhook retry racing its original
        }

        $this->chatLogger->info('cleaning comment captured', [
            'message_id' => $m->message_id,
            'photo' => $fileId !== null,
            'reply_to_report' => $comment->getReportAt() !== null,
        ]);

        $this->bus->dispatch(new CleaningCommentFlushMessage(), [new DelayStamp(self::QUIET_MS)]);
    }

    /** Residents and the board — not bots, not the crew, not a linked channel. */
    private function isFromTheHouse(Message $m): bool
    {
        // The board writing «as the group» (anonymous admin) arrives with the group as sender.
        if ($m->sender_chat !== null) {
            return (string)$m->sender_chat->id === $this->residentChatId;
        }

        $from = $m->from;

        if ($from === null || $from->is_bot) {
            return false;
        }

        return $this->staff->findActiveByTelegramId((string)$from->id) === null;
    }

    /** The photo report this comment answers, if it is a reply to one of the bot's posts. */
    private function reportTime(Message $m): ?\DateTimeImmutable
    {
        $r = $m->reply_to_message;

        // In a topic every message «replies» to the topic's first message; that is no reply.
        if ($r === null || $r->message_id === $m->message_thread_id || $r->from?->is_bot !== true || ($r->photo ?? []) === []) {
            return null;
        }

        return new \DateTimeImmutable('@' . $r->date);
    }

    /**
     * The text with everything that points at a person cut out: @mentions, mentions of
     * people without a username, links to profiles (tg://user, t.me/…), phone numbers and
     * e-mails. Offsets are UTF-16 code units, as Telegram counts them.
     *
     * @param MessageEntity[]|null $entities
     */
    public static function anonymise(?string $text, ?array $entities): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $cut = [];
        foreach ($entities ?? [] as $e) {
            $type = $e->type instanceof MessageEntityType ? $e->type->value : (string)$e->type;
            $url = (string)$e->url;

            $strip = match ($type) {
                'mention', 'text_mention', 'phone_number', 'email' => true,
                'text_link' => str_starts_with($url, 'tg://') || preg_match('~(^|//)(t\.me|telegram\.me)/~i', $url) === 1,
                default => false,
            };

            if ($strip) {
                $cut[] = [$e->offset, $e->length];
            }
        }

        if ($cut !== []) {
            $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
            usort($cut, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
            foreach ($cut as [$offset, $length]) {
                $utf16 = substr_replace($utf16, '', $offset * 2, $length * 2);
            }
            $text = mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
        }

        // The same, for what Telegram did not mark up (or a client did not send entities for).
        $text = preg_replace('~(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/\S+~iu', '', $text);
        $text = preg_replace('~tg://\S+~iu', '', $text);
        $text = preg_replace('~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u', '', $text);
        $text = preg_replace('~(?<![\w@])@[A-Za-z][A-Za-z0-9_]{3,31}\b~u', '', $text);
        $text = preg_replace_callback(
            '~(?<!\d)\+?\d[\d \t()\-]{7,}\d(?!\d)~u',
            static fn (array $m): string => strlen(preg_replace('~\D~', '', $m[0])) >= 9 ? '' : $m[0],
            $text,
        );

        $lines = array_map(static fn (string $l): string => trim(preg_replace('~[ \t]{2,}~u', ' ', $l)), explode("\n", $text));

        return trim(preg_replace("~\n{3,}~", "\n\n", implode("\n", $lines)));
    }

    /**
     * Send whatever has gathered. Runs on the async worker, one job per captured comment; a
     * job that finds a comment newer than its own wait leaves the batch to that comment's job.
     */
    public function flush(): void
    {
        $rows = $this->comments->findUnrelayed();

        if ($rows === []) {
            return;
        }

        $now = time();
        $times = array_map(static fn (CleaningComment $c): int => $c->getCreatedAt()->getTimestamp(), $rows);
        $quietFor = $now - max($times);
        $waited = $now - min($times);

        if ($quietFor < intdiv(self::QUIET_MS, 1000) - 1 && $waited < self::MAX_WAIT_S) {
            // Look again later rather than trust a newer comment's job to exist: its dispatch
            // may have failed, or the clocks may disagree by a second. A spare job finds the
            // rows already claimed and does nothing.
            $this->bus->dispatch(new CleaningCommentFlushMessage(), [new DelayStamp(self::QUIET_MS)]);

            return;
        }

        // Claimed before sending: a sibling job then finds nothing, and a failure for one
        // worker never turns into a second copy for another.
        foreach ($rows as $row) {
            $row->markRelayed();
        }
        $this->em->flush();

        $recipients = $this->staff->findReachableCleaning();

        if ($recipients === []) {
            $this->chatLogger->warning('cleaning comments not relayed: no linked cleaning staff', ['count' => count($rows)]);

            return;
        }

        foreach ($recipients as $i => $staff) {
            if ($i > 0) {
                $this->pause();
            }

            try {
                $this->deliver($staff, $rows);
            } catch (\Throwable $e) {
                $this->chatLogger->warning('cleaning comments not delivered', [
                    'staff_id' => $staff->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->chatLogger->info('cleaning comments relayed', [
            'count' => count($rows),
            'recipients' => count($recipients),
        ]);
    }

    /** @param CleaningComment[] $rows */
    private function deliver(ServiceStaff $staff, array $rows): void
    {
        $chatId = (string)$staff->getTelegramId();
        $photos = array_values(array_filter(array_map(static fn (CleaningComment $c): ?string => $c->getFileId(), $rows)));
        $groups = self::group($rows);

        // The usual case — one comment, a photo or two: a single post with the text under it.
        $inline = self::render($groups, false);
        if ($photos !== [] && count($photos) <= 10 && count($inline) === 1 && mb_strlen($inline[0]) <= self::CAPTION_LIMIT) {
            $this->sendPhotos($chatId, $photos, $inline[0]);

            return;
        }

        foreach (self::render($groups, $photos !== []) as $i => $text) {
            if ($i > 0) {
                $this->pause();
            }
            $this->bot->sendMessage(text: $text, chat_id: $chatId);
        }

        foreach (array_chunk($photos, 10) as $i => $chunk) {
            $this->pause();
            $this->sendPhotos($chatId, $chunk, $i === 0 ? self::PHOTOS_HEAD : null);
        }
    }

    /** @param string[] $fileIds */
    private function sendPhotos(string $chatId, array $fileIds, ?string $caption): void
    {
        if (count($fileIds) === 1) {
            $this->bot->sendPhoto(photo: $fileIds[0], chat_id: $chatId, caption: $caption);

            return;
        }

        $media = [];
        foreach ($fileIds as $i => $fileId) {
            $media[] = $i === 0 && $caption !== null
                ? InputMediaPhoto::make($fileId, caption: $caption)
                : InputMediaPhoto::make($fileId);
        }

        $this->bot->sendMediaGroup(media: $media, chat_id: $chatId);
    }

    /**
     * Rows of one album are one comment.
     *
     * @param CleaningComment[] $rows
     * @return array<int, array{at: \DateTime, report: ?\DateTime, text: string, photos: int}>
     */
    public static function group(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $row->getMediaGroupId() ?? 'm' . $row->getChatMessageId();
            $g = $groups[$key] ?? ['at' => $row->getCreatedAt(), 'report' => null, 'texts' => [], 'photos' => 0];

            $g['report'] ??= $row->getReportAt();
            if ($row->getText() !== null) {
                $g['texts'][] = $row->getText();
            }
            $g['photos'] += $row->getFileId() !== null ? 1 : 0;
            $groups[$key] = $g;
        }

        return array_values(array_map(static fn (array $g): array => [
            'at' => $g['at'],
            'report' => $g['report'],
            'text' => implode("\n", array_unique($g['texts'])),
            'photos' => $g['photos'],
        ], $groups));
    }

    /**
     * The message(s) a worker reads. $markPhotos: the photos follow separately, so each
     * comment says it had some.
     *
     * @param array<int, array{at: \DateTime, report: ?\DateTime, text: string, photos: int}> $groups
     * @return string[]
     */
    public static function render(array $groups, bool $markPhotos): array
    {
        $report = static fn (?\DateTime $at, string $fmt): string => $at !== null ? ' до звіту від ' . $at->format($fmt) : '';
        $photoNote = static fn (array $g): string => $markPhotos && $g['photos'] > 0 ? sprintf('📷 %d фото', $g['photos']) : '';

        if (count($groups) === 1) {
            $g = $groups[0];
            $body = trim($g['text'] . ($g['text'] !== '' && $photoNote($g) !== '' ? ' · ' : '') . $photoNote($g));
            $text = self::SINGLE_HEAD . $report($g['report'], 'd.m.Y, H:i') . ':' . ($body !== '' ? "\n" . $body : '');

            return [mb_substr($text, 0, self::TEXT_LIMIT)];
        }

        $blocks = [];
        foreach ($groups as $g) {
            $body = $g['text'] !== '' ? $g['text'] : ($g['photos'] > 0 ? 'фото без підпису' : '');
            $note = $photoNote($g);
            $blocks[] = '• ' . $g['at']->format('H:i') . ($g['report'] !== null ? ',' . $report($g['report'], 'd.m, H:i') : '')
                . ': ' . $body . ($note !== '' ? ' · ' . $note : '');
        }

        $messages = [];
        $current = self::BATCH_HEAD . ' (' . count($groups) . '):';
        foreach ($blocks as $block) {
            $block = mb_substr($block, 0, self::TEXT_LIMIT - 10);
            if (mb_strlen($current) + 2 + mb_strlen($block) > self::TEXT_LIMIT) {
                $messages[] = $current;
                $current = $block;
            } else {
                $current .= "\n\n" . $block;
            }
        }
        $messages[] = $current;

        return $messages;
    }

    /**
     * A worker answering a relayed comment in their private chat. Their photo still goes on
     * as a report; anything else would vanish, so it is told where to go instead.
     */
    public function isReplyToRelay(?Message $m): bool
    {
        $r = $m?->reply_to_message;

        // A photo stays a report; a command still works.
        if ($r === null || $r->from?->is_bot !== true || ($m->photo ?? []) !== [] || str_starts_with((string)$m->text, '/')) {
            return false;
        }

        $quoted = (string)($r->text ?? $r->caption);

        // The 2nd…10th photo of a relayed album has no caption; the bot sends a worker no
        // other photos, so a captionless one is a relayed one.
        if ($quoted === '' && ($r->photo ?? []) !== []) {
            return true;
        }

        return str_starts_with($quoted, self::SINGLE_HEAD)
            || str_starts_with($quoted, self::BATCH_HEAD)
            || str_starts_with($quoted, self::PHOTOS_HEAD);
    }

    protected function pause(): void
    {
        if ($this->pauseMicros > 0) {
            usleep($this->pauseMicros);
        }
    }
}
