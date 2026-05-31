<?php

namespace App\Telegram\SchedulePavilion\Command;

use App\Entity\PavilionPhoto;
use App\Entity\PhotoUploadRequest;
use App\Entity\ScheduledSet;
use App\Repository\PavilionPhotoRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Service\PavilionPhotoService;
use App\Service\SchedulePavilionService;
use App\Service\UkDateFormatter;
use App\Telegram\Start\Command\StartCommand;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BookingHistory
{
    public const HISTORY_DAYS = 30;
    private const MESSAGE_CHAR_LIMIT = 3800;
    public const CALLBACK_PREFIX = 'bh:week:';
    public const PHOTO_CALLBACK_PREFIX = 'bh:photo:';
    private const PHOTO_BUTTONS_PER_ROW = 2;
    private const PHOTO_BUTTONS_LIMIT = 30;

    /** @var array<string, PavilionPhoto> account_id:pavilion:Y-m-d H:i => photo */
    private array $photoByHourKey = [];
    /** @var array<string, PhotoUploadRequest> */
    private array $reqByHourKey = [];
    /** @var PavilionPhoto[] one entry per unique session photo in the week, oldest first */
    private array $weekPhotos = [];
    private \DateTime $obligationStart;

    public function __construct(
        private SchedulePavilionService $schedulePavilionService,
        private PavilionPhotoRepository $photoRepository,
        private PhotoUploadRequestRepository $requestRepository,
        private PavilionPhotoService $photoService,
        private EntityManagerInterface $em,
        private CacheItemPoolInterface $cache,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $data = (string)($bot->callbackQuery()->data ?? '');
            if (str_starts_with($data, self::PHOTO_CALLBACK_PREFIX)) {
                $this->sendPhoto($bot, (int)substr($data, strlen(self::PHOTO_CALLBACK_PREFIX)));
                return;
            }
        }

        $now = SchedulePavilionService::createNewDate();
        $anchor = $this->resolveAnchor($bot, $now);

        [$weekStart, $weekEnd] = $this->weekBoundsForAnchor($anchor);

        $history = $this->schedulePavilionService->getHistoryRange($weekStart, $weekEnd);
        $this->preloadStatusIndex($weekStart, $weekEnd);

        $chunks = $this->renderChunks($history, $weekStart, $weekEnd);
        $markup = $this->buildNavigation($weekStart, $now);

        if (count($chunks) === 1) {
            $this->renderSingle($bot, $chunks[0], $markup);
        } else {
            $this->renderMultiple($bot, $chunks, $markup);
        }

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }
    }

    /**
     * Fits in one message: keep the original edit-in-place behaviour (no flicker,
     * message stays put). Clean up any leftover overflow messages from a prior
     * multi-message render of this chat.
     */
    private function renderSingle(Nutgram $bot, string $text, InlineKeyboardMarkup $markup): void
    {
        $chatId = $bot->chatId();
        $callbackMsgId = $bot->isCallbackQuery() ? ($bot->callbackQuery()->message?->message_id) : null;

        // Remove any previously-sent batch messages except the one we'll edit.
        $this->deleteBatch($bot, $chatId, $callbackMsgId);

        if ($callbackMsgId !== null) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
                $this->saveBatch($chatId, [$callbackMsgId]);
                return;
            } catch (\Throwable) {
                // fall through to a fresh send
            }
        }

        $msg = $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
        $this->saveBatch($chatId, $msg?->message_id !== null ? [$msg->message_id] : []);
    }

    /**
     * Too big for one message: send the week as a sequence of messages (split on
     * day boundaries), navigation on the last one. The previous batch is deleted
     * first so views don't pile up as the user navigates weeks.
     */
    private function renderMultiple(Nutgram $bot, array $chunks, InlineKeyboardMarkup $markup): void
    {
        $chatId = $bot->chatId();
        $this->deleteBatch($bot, $chatId, null);

        $sentIds = [];
        $last = count($chunks) - 1;
        foreach ($chunks as $i => $text) {
            $msg = $bot->sendMessage(
                text: $text,
                parse_mode: ParseMode::HTML,
                reply_markup: $i === $last ? $markup : null,
            );
            if ($msg?->message_id !== null) {
                $sentIds[] = $msg->message_id;
            }
        }
        $this->saveBatch($chatId, $sentIds);
    }

    private function batchKey(int|string $chatId): string
    {
        return 'bh_batch_' . $chatId;
    }

    /** Delete the previously-tracked batch messages for this chat, optionally keeping one. */
    private function deleteBatch(Nutgram $bot, int|string|null $chatId, ?int $keepMessageId): void
    {
        if ($chatId === null) {
            return;
        }
        $item = $this->cache->getItem($this->batchKey($chatId));
        if (!$item->isHit()) {
            return;
        }
        foreach ((array)$item->get() as $mid) {
            $mid = (int)$mid;
            if ($mid === $keepMessageId) {
                continue;
            }
            try {
                $bot->deleteMessage($chatId, $mid);
            } catch (\Throwable) {
                // message too old / already gone — ignore
            }
        }
        $this->cache->deleteItem($this->batchKey($chatId));
    }

    private function saveBatch(int|string|null $chatId, array $messageIds): void
    {
        if ($chatId === null || !$messageIds) {
            return;
        }
        $item = $this->cache->getItem($this->batchKey($chatId));
        $item->set(array_values($messageIds));
        $item->expiresAfter(3600);
        $this->cache->save($item);
    }

    private function sendPhoto(Nutgram $bot, int $photoId): void
    {
        $photo = $this->em->getRepository(PavilionPhoto::class)->find($photoId);
        if (!$photo) {
            $bot->answerCallbackQuery(text: '📷 Фото не знайдено.', show_alert: true);
            return;
        }

        $bot->answerCallbackQuery();

        $caption = $this->buildPhotoCaption($photo);

        try {
            if ($photo->getTelegramFileId()) {
                $bot->sendPhoto(photo: $photo->getTelegramFileId(), caption: $caption, parse_mode: ParseMode::HTML);
                return;
            }
        } catch (\Throwable) {
            // file_id expired or invalid - fall back to file path
        }

        $fsPath = $this->resolveFsPath($photo->getFilePath());
        if ($fsPath && is_readable($fsPath)) {
            $stream = fopen($fsPath, 'rb');
            if ($stream !== false) {
                $bot->sendPhoto(
                    photo: InputFile::make($stream, basename($fsPath)),
                    caption: $caption,
                    parse_mode: ParseMode::HTML,
                );
                return;
            }
        }

        $bot->sendMessage(text: '⚠️ Файл фото недоступний.');
    }

    private function resolveFsPath(string $publicPath): ?string
    {
        $rel = ltrim($publicPath, '/');
        if (!str_starts_with($rel, 'uploads/pavilion-photos/')) {
            return null;
        }
        $base = realpath(__DIR__ . '/../../../../public');
        return $base ? $base . '/' . $rel : null;
    }

    private function buildPhotoCaption(PavilionPhoto $photo): string
    {
        $start = $photo->getSessionStartAt();
        $pavilionName = $photo->getPavilion() === 1 ? 'Перша' : 'Друга';
        $account = $photo->getAccount();

        $bookers = $this->em->createQuery(
            'SELECT ss, tu FROM App\Entity\ScheduledSet ss JOIN ss.telegramUserId tu '
            . 'WHERE tu.account = :account AND ss.pavilion = :pavilion '
            . 'AND ss.scheduled_at >= :start AND ss.scheduled_at < :end '
            . 'ORDER BY ss.scheduled_at ASC'
        )
            ->setParameter('account', $account)
            ->setParameter('pavilion', $photo->getPavilion())
            ->setParameter('start', $start)
            ->setParameter('end', $photo->getSessionEndAt())
            ->getResult();

        $bookerLine = '';
        if ($bookers) {
            $tu = $bookers[0]->getTelegramUserId();
            $name = trim(($tu->getFirstName() ?? '') . ' ' . ($tu->getLastName() ?? ''));
            if ($name === '') {
                $name = $tu->getUsername() ? '@' . $tu->getUsername() : '—';
            } elseif ($tu->getUsername()) {
                $name .= ' (@' . $tu->getUsername() . ')';
            }
            $phone = $tu->getPhoneNumber() ?: '—';
            $bookerLine = sprintf(
                "\n👤 %s\n📞 %s",
                $this->escape($name),
                $this->escape($phone),
            );
        }

        $address = trim(sprintf(
            '%s %s, кв. %s',
            $account->getStreet() ?? '',
            $account->getHouseNumber() ?? '',
            $account->getApartmentNumber() ?? '',
        ));

        $uploader = $photo->getUploader();
        $uploaderLine = '';
        if ($uploader && (!$bookers || $uploader->getId() !== $bookers[0]->getTelegramUserId()->getId())) {
            $upName = trim(($uploader->getFirstName() ?? '') . ' ' . ($uploader->getLastName() ?? ''));
            if ($upName === '' && $uploader->getUsername()) {
                $upName = '@' . $uploader->getUsername();
            }
            if ($upName !== '') {
                $uploaderLine = "\n📸 Завантажив(ла): " . $this->escape($upName);
            }
        }

        return sprintf(
            "📷 <b>%s · %s</b>\n🏠 Альтанка: <b>%s</b>%s\n🏠 %s%s",
            UkDateFormatter::dayDate($start),
            UkDateFormatter::time($start),
            $pavilionName,
            $bookerLine,
            $this->escape($address),
            $uploaderLine,
        );
    }

    private function resolveAnchor(Nutgram $bot, \DateTime $now): \DateTime
    {
        if (!$bot->isCallbackQuery()) {
            return clone $now;
        }
        $data = (string)($bot->callbackQuery()->data ?? '');
        if (!str_starts_with($data, self::CALLBACK_PREFIX)) {
            return clone $now;
        }
        $iso = substr($data, strlen(self::CALLBACK_PREFIX));
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $iso, $m)) {
            return clone $now;
        }
        $year = (int)$m[1];
        $week = (int)$m[2];
        $anchor = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $anchor->setISODate($year, $week, 1)->setTime(12, 0, 0);
        return $anchor;
    }

    /**
     * @return array{0:\DateTime, 1:\DateTime} [monday 00:00, next-monday 00:00)
     */
    private function weekBoundsForAnchor(\DateTime $anchor): array
    {
        $year = (int)$anchor->format('o');
        $week = (int)$anchor->format('W');

        $start = (new \DateTime('now', new \DateTimeZone('Europe/Kyiv')))
            ->setISODate($year, $week, 1)
            ->setTime(0, 0, 0);
        $end = (clone $start)->modify('+7 days');

        return [$start, $end];
    }

    private function buildNavigation(\DateTime $weekStart, \DateTime $now): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();

        $prevAnchor = (clone $weekStart)->modify('-7 days');
        $nextAnchor = (clone $weekStart)->modify('+7 days');

        $oldestAllowed = (clone $now)->modify('-' . self::HISTORY_DAYS . ' days');
        $prevAllowed = $prevAnchor >= (clone $oldestAllowed)->modify('-7 days');
        $nextAllowed = $nextAnchor <= $now;

        $navRow = [];
        if ($prevAllowed) {
            $navRow[] = InlineKeyboardButton::make(
                '⬅️ Попередній тиждень',
                callback_data: self::CALLBACK_PREFIX . $prevAnchor->format('o-\WW'),
            );
        }
        if ($nextAllowed) {
            $navRow[] = InlineKeyboardButton::make(
                'Наступний тиждень ➡️',
                callback_data: self::CALLBACK_PREFIX . $nextAnchor->format('o-\WW'),
            );
        }
        if ($navRow) {
            $markup->addRow(...$navRow);
        }

        $thisWeek = (clone $now)->setTime(12, 0, 0);
        if ($weekStart->format('o-W') !== $thisWeek->format('o-W')) {
            $markup->addRow(
                InlineKeyboardButton::make(
                    '📅 Поточний тиждень',
                    callback_data: self::CALLBACK_PREFIX . $thisWeek->format('o-\WW'),
                )
            );
        }

        $this->appendPhotoButtons($markup);

        $markup->addRow(StartCommand::homeButton());

        return $markup;
    }

    private function appendPhotoButtons(InlineKeyboardMarkup $markup): void
    {
        if (!$this->weekPhotos) {
            return;
        }

        $photos = array_slice($this->weekPhotos, 0, self::PHOTO_BUTTONS_LIMIT);
        $row = [];
        foreach ($photos as $p) {
            $start = $p->getSessionStartAt();
            $label = sprintf('📷 %s %s · А%d',
                $start->format('d.m'),
                $start->format('H:i'),
                $p->getPavilion(),
            );
            $row[] = InlineKeyboardButton::make(
                $label,
                callback_data: self::PHOTO_CALLBACK_PREFIX . $p->getId(),
            );
            if (count($row) === self::PHOTO_BUTTONS_PER_ROW) {
                $markup->addRow(...$row);
                $row = [];
            }
        }
        if ($row) {
            $markup->addRow(...$row);
        }
    }

    /**
     * Render the week into one or more message-sized chunks. Whole days are kept
     * together and packed greedily up to MESSAGE_CHAR_LIMIT; a single day larger
     * than the limit is split between its entries (day header repeated) so no
     * booking is ever dropped. Returns a single chunk for a normal week.
     *
     * @param array<string, ScheduledSet[]> $history
     * @return string[]
     */
    private function renderChunks(array $history, \DateTime $weekStart, \DateTime $weekEnd): array
    {
        $weekEndDisplay = (clone $weekEnd)->modify('-1 day');
        $header = sprintf(
            "📜 <b>Історія бронювань</b>\nТиждень %s — %s\n",
            $weekStart->format('d.m'),
            $weekEndDisplay->format('d.m.Y'),
        );

        if (!$history) {
            return [$header . "\n<i>Цього тижня бронювань не було.</i>"];
        }

        $days = [];
        foreach ($history as $sets) {
            $first = $sets[0]->getScheduledDateTime();
            $dayHeader = "\n📅 <b>" . UkDateFormatter::dayDate($first) . "</b>\n";
            $entries = array_map(fn(ScheduledSet $set) => $this->formatEntry($set), $sets);
            $days[] = ['header' => $dayHeader, 'entries' => $entries];
        }

        $chunks = [];
        $current = $header;

        foreach ($days as $day) {
            $dayText = $day['header'] . implode('', $day['entries']);

            if (mb_strlen($current . $dayText, 'UTF-8') <= self::MESSAGE_CHAR_LIMIT) {
                $current .= $dayText;
                continue;
            }

            // The day won't fit in the current message — close it out.
            if (trim($current) !== '') {
                $chunks[] = $current;
            }
            $current = '';

            if (mb_strlen($dayText, 'UTF-8') <= self::MESSAGE_CHAR_LIMIT) {
                $current = $dayText;
                continue;
            }

            // A single day exceeds the limit: split it between entries.
            $piece = $day['header'];
            foreach ($day['entries'] as $entry) {
                if ($piece !== $day['header']
                    && mb_strlen($piece . $entry, 'UTF-8') > self::MESSAGE_CHAR_LIMIT) {
                    $chunks[] = $piece;
                    $piece = $day['header'];
                }
                $piece .= $entry;
            }
            $current = $piece;
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return $chunks ?: [$header];
    }

    private function preloadStatusIndex(\DateTime $weekStart, \DateTime $weekEnd): void
    {
        $this->photoByHourKey = [];
        $this->reqByHourKey = [];
        $this->weekPhotos = [];
        $this->obligationStart = $this->photoService->obligationStartAt();

        $from = (clone $weekStart)->modify('-1 day');
        $until = (clone $weekEnd)->modify('+1 day');

        $photos = $this->em->createQuery(
            'SELECT p, a FROM App\Entity\PavilionPhoto p JOIN p.account a '
            . 'WHERE p.session_start_at BETWEEN :from AND :until '
            . 'ORDER BY p.session_start_at ASC'
        )->setParameter('from', $from)->setParameter('until', $until)->getResult();

        $requests = $this->em->createQuery(
            'SELECT r, a FROM App\Entity\PhotoUploadRequest r JOIN r.account a '
            . 'WHERE r.session_start_at BETWEEN :from AND :until AND r.resolved_at IS NULL'
        )->setParameter('from', $from)->setParameter('until', $until)->getResult();

        /** @var PavilionPhoto $p */
        foreach ($photos as $p) {
            $this->indexBySessionHours(
                $this->photoByHourKey,
                $p->getAccount()->getId(), $p->getPavilion(),
                $p->getSessionStartAt(), $p->getSessionEndAt(), $p,
            );
            if ($p->getSessionStartAt() >= $weekStart && $p->getSessionStartAt() < $weekEnd) {
                $this->weekPhotos[] = $p;
            }
        }
        /** @var PhotoUploadRequest $r */
        foreach ($requests as $r) {
            $this->indexBySessionHours(
                $this->reqByHourKey,
                $r->getAccount()->getId(), $r->getPavilion(),
                $r->getSessionStartAt(), $r->getSessionEndAt(), $r,
            );
        }
    }

    private function indexBySessionHours(array &$bag, int $accountId, int $pavilion, \DateTimeInterface $start, \DateTimeInterface $end, object $value): void
    {
        $cursor = clone $start;
        $endTs = $end->getTimestamp();
        while ($cursor->getTimestamp() < $endTs) {
            $key = sprintf('%d:%d:%s', $accountId, $pavilion, $cursor->format('Y-m-d H:i'));
            $bag[$key] = $value;
            $cursor->modify('+1 hour');
        }
    }

    private function formatEntry(ScheduledSet $set): string
    {
        $dt = $set->getScheduledDateTime();
        $tu = $set->getTelegramUserId();
        $account = $tu->getAccount();

        $name = trim(($tu->getFirstName() ?? '') . ' ' . ($tu->getLastName() ?? ''));
        if ($name === '') {
            $name = $tu->getUsername() ? '@' . $tu->getUsername() : '—';
        } elseif ($tu->getUsername()) {
            $name .= ' (@' . $tu->getUsername() . ')';
        }

        $phone = $tu->getPhoneNumber() ?: '—';

        if ($account) {
            $address = trim(sprintf(
                '%s %s, кв. %s',
                $account->getStreet() ?? '',
                $account->getHouseNumber() ?? '',
                $account->getApartmentNumber() ?? '',
            ));
        } else {
            $address = '—';
        }

        $status = $this->statusIndicator($set);

        return sprintf(
            "   🕒 %s · Альт. %d · %s · 📞 %s · 🏠 %s%s\n",
            $dt->format('H:i'),
            $set->getPavilion(),
            $this->escape($name),
            $this->escape($phone),
            $this->escape($address),
            $status === '' ? '' : ' · ' . $status,
        );
    }

    private function statusIndicator(ScheduledSet $set): string
    {
        $tu = $set->getTelegramUserId();
        $account = $tu->getAccount();
        if (!$account) {
            return '';
        }
        $hourEnd = (clone $set->getScheduledDateTime())->modify('+1 hour');
        if ($hourEnd <= $this->obligationStart) {
            return '';
        }
        $key = sprintf('%d:%d:%s', $account->getId(), $set->getPavilion(), $set->getScheduledDateTime()->format('Y-m-d H:i'));
        if (isset($this->photoByHourKey[$key])) {
            return '📷';
        }
        if (isset($this->reqByHourKey[$key])) {
            return $this->reqByHourKey[$key]->getBlockedAt() ? '⛔' : '⏳';
        }
        return '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
