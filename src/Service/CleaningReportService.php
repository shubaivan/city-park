<?php

namespace App\Service;

use App\Entity\CleaningReportPhoto;
use App\Entity\ServiceStaff;
use App\Message\CleaningAlbumFlushMessage;
use App\Repository\CleaningReportPhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The cleaning crew's photo report (Иван, 16.11.2026): photos sent to the bot are reposted
 * by the bot into the «🧹 Прибирання» topic of the residents' chat, signed
 * «🧹 Клінінг · <дата, час>» with the worker's own text under it.
 *
 * The worker never writes into the chat — the bot does, so the topic stays the residents'
 * to discuss. A single photo is posted on the spot; an album arrives as one update per
 * photo, so its photos are stored and a delayed job posts them as one sendMediaGroup.
 *
 * No General fallback: an unset RESIDENT_CHAT_TOPIC_CLEANING refuses the report instead of
 * dropping photos of stairwells into the chat's main thread.
 */
class CleaningReportService
{
    /** How long an album is waited for after one of its photos arrives. */
    public const ALBUM_DELAY_MS = 4000;

    /** Telegram's limit on a media caption. */
    private const CAPTION_LIMIT = 1024;

    public const SENT_TEXT = '✅ Фотозвіт надіслано';

    public function __construct(
        private CleaningReportPhotoRepository $photos,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private Nutgram $bot,
        private LoggerInterface $photoLogger,
        private string $residentChatId = '',
        private string $topicCleaning = '',
    ) {}

    public function topic(): ?int
    {
        return ctype_digit($this->topicCleaning) && $this->topicCleaning !== '0' ? (int)$this->topicCleaning : null;
    }

    public function isConfigured(): bool
    {
        return $this->residentChatId !== '' && $this->topic() !== null;
    }

    /** «🧹 Клінінг · 16.11.2026, 20:15» + the worker's text, HTML-escaped. */
    public static function caption(string $label, \DateTimeInterface $at, array $texts): string
    {
        $kyiv = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('Europe/Kyiv'));
        $head = sprintf('🧹 <b>%s</b> · %s', self::esc($label), $kyiv->format('d.m.Y, H:i'));

        $body = implode("\n", array_map(self::esc(...), array_values(array_unique(array_filter(
            array_map(static fn (?string $t): string => trim((string)$t), $texts),
            static fn (string $t): bool => $t !== '',
        )))));

        $caption = $body === '' ? $head : $head . "\n\n" . $body;

        if (mb_strlen($caption) > self::CAPTION_LIMIT) {
            // Cut on the plain body and re-escape would be nicer; a cut tag would break the
            // HTML parse and the whole post with it, so drop the body's tail instead.
            $caption = mb_substr($caption, 0, self::CAPTION_LIMIT - 1);
            $caption = preg_replace('/&[a-z#0-9]*$/i', '', $caption) . '…';
        }

        return $caption;
    }

    /**
     * One incoming photo from a staff member. Never throws — a throw here is a /hook 500 and
     * Telegram would retry the same photo for an hour.
     */
    public function handle(Nutgram $bot, ServiceStaff $staff): void
    {
        try {
            $this->doHandle($bot, $staff);
        } catch (\Throwable $e) {
            $this->photoLogger->error('cleaning report failed', [
                'chat_id' => $bot->chatId(),
                'error' => $e->getMessage(),
            ]);

            try {
                $bot->sendMessage(text: '⚠️ Не вдалося надіслати фотозвіт. Спробуйте, будь ласка, ще раз за хвилину.');
            } catch (\Throwable) {
                // must never bubble up into a webhook 500
            }
        }
    }

    private function doHandle(Nutgram $bot, ServiceStaff $staff): void
    {
        $message = $bot->message();
        $sizes = $message?->photo ?? [];
        $largest = $sizes !== [] ? $sizes[array_key_last($sizes)] : null;

        if ($message === null || $largest === null) {
            return;
        }

        if (!$this->isConfigured()) {
            $this->photoLogger->warning('cleaning report refused: RESIDENT_CHAT_TOPIC_CLEANING not set');
            $bot->sendMessage(text: '⚠️ Фотозвіти тимчасово не приймаються. Повідомте, будь ласка, адміністратора бота.');

            return;
        }

        $chatId = (string)$bot->chatId();
        $messageId = (int)$message->message_id;

        // A webhook retry of a photo already taken.
        if ($this->photos->findByMessage($chatId, $messageId) !== null) {
            return;
        }

        $photo = new CleaningReportPhoto(
            $staff,
            $chatId,
            $messageId,
            $largest->file_id,
            $message->caption,
            $message->media_group_id,
            $staff->getLabel(),
        );
        $this->em->persist($photo);
        $this->em->flush();

        $this->photoLogger->info('cleaning report photo received', [
            'staff_id' => $staff->getId(),
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'media_group_id' => $photo->getMediaGroupId(),
        ]);

        if ($photo->getMediaGroupId() !== null) {
            // One job per photo; the first to run posts the whole album, the rest find
            // nothing left. Cheaper than deciding which photo is "the first".
            $this->bus->dispatch(
                new CleaningAlbumFlushMessage($photo->getMediaGroupId()),
                [new DelayStamp(self::ALBUM_DELAY_MS)],
            );

            return;
        }

        $this->bot->sendPhoto(
            photo: $photo->getFileId(),
            chat_id: $this->residentChatId,
            message_thread_id: $this->topic(),
            caption: self::caption($photo->getLabel(), $photo->getCreatedAt(), [$photo->getCaption()]),
            parse_mode: ParseMode::HTML,
        );

        // Posted: from here on nothing may tell the worker «спробуйте ще раз» — a resend
        // would be a second post.
        try {
            $photo->markPosted();
            $this->em->flush();
            $this->photoLogger->info('cleaning report posted', ['photo_id' => $photo->getId(), 'count' => 1]);
            $bot->sendMessage(text: self::SENT_TEXT);
        } catch (\Throwable $e) {
            $this->photoLogger->warning('cleaning report posted, bookkeeping failed', ['error' => $e->getMessage()]);
        }
    }

    /** Post every not-yet-posted photo of one album as one chat post (10 per Telegram post). */
    public function flushAlbum(string $mediaGroupId): void
    {
        $rows = $this->photos->findUnpostedInGroup($mediaGroupId);

        if ($rows === [] || !$this->isConfigured()) {
            return;
        }

        // A photo of this album arrived only just now — more may still be on the way.
        $newest = max(array_map(static fn (CleaningReportPhoto $p): int => $p->getCreatedAt()->getTimestamp(), $rows));
        if (time() - $newest < 3) {
            $this->bus->dispatch(new CleaningAlbumFlushMessage($mediaGroupId), [new DelayStamp(self::ALBUM_DELAY_MS)]);

            return;
        }

        $caption = self::caption(
            $rows[0]->getLabel(),
            $rows[0]->getCreatedAt(),
            array_map(static fn (CleaningReportPhoto $p): ?string => $p->getCaption(), $rows),
        );

        foreach (array_chunk($rows, 10) as $i => $chunk) {
            $media = [];

            foreach ($chunk as $j => $row) {
                $media[] = $i === 0 && $j === 0
                    ? InputMediaPhoto::make($row->getFileId(), caption: $caption, parse_mode: ParseMode::HTML)
                    : InputMediaPhoto::make($row->getFileId());
            }

            // Claimed before sending: the album's sibling jobs then find nothing to post.
            foreach ($chunk as $row) {
                $row->markPosted();
            }
            $this->em->flush();

            try {
                $this->send($chunk, $media, $i === 0 ? $caption : null);
            } catch (\Throwable $e) {
                foreach ($chunk as $row) {
                    $row->unmarkPosted();
                }
                $this->em->flush();

                throw $e;
            }
        }

        $this->photoLogger->info('cleaning report album posted', [
            'media_group_id' => $mediaGroupId,
            'count' => count($rows),
        ]);

        try {
            $this->bot->sendMessage(
                text: sprintf('%s (%d фото)', self::SENT_TEXT, count($rows)),
                chat_id: $rows[0]->getChatId(),
            );
        } catch (\Throwable $e) {
            $this->photoLogger->warning('cleaning report: could not confirm to worker', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param CleaningReportPhoto[] $chunk
     * @param InputMediaPhoto[] $media
     */
    private function send(array $chunk, array $media, ?string $caption): void
    {
        if (count($media) === 1) {
            $this->bot->sendPhoto(
                photo: $chunk[0]->getFileId(),
                chat_id: $this->residentChatId,
                message_thread_id: $this->topic(),
                caption: $caption,
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        $this->bot->sendMediaGroup(
            media: $media,
            chat_id: $this->residentChatId,
            message_thread_id: $this->topic(),
        );
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
