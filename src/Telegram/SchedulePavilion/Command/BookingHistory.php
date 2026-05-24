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

        $text = $this->renderText($history, $weekStart, $weekEnd);
        $markup = $this->buildNavigation($weekStart, $now);

        $isCallback = $bot->isCallbackQuery();
        if ($isCallback) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
                return;
            } catch (\Throwable) {
                // fall through
            }
        }
        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
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
     * @param array<string, ScheduledSet[]> $history
     */
    private function renderText(array $history, \DateTime $weekStart, \DateTime $weekEnd): string
    {
        $weekEndDisplay = (clone $weekEnd)->modify('-1 day');
        $header = sprintf(
            "📜 <b>Історія бронювань</b>\nТиждень %s — %s\n",
            $weekStart->format('d.m'),
            $weekEndDisplay->format('d.m.Y'),
        );

        if (!$history) {
            return $header . "\n<i>Цього тижня бронювань не було.</i>";
        }

        $body = '';
        foreach ($history as $sets) {
            $first = $sets[0]->getScheduledDateTime();
            $section = "\n📅 <b>" . UkDateFormatter::dayDate($first) . "</b>\n";
            foreach ($sets as $set) {
                $section .= $this->formatEntry($set);
            }
            $body .= $section;
        }

        $text = $header . $body;
        if (mb_strlen($text, 'UTF-8') > self::MESSAGE_CHAR_LIMIT) {
            $text = mb_substr($text, 0, self::MESSAGE_CHAR_LIMIT - 80, 'UTF-8')
                . "\n\n<i>… забагато записів, скорочено. Використайте навігацію по днях у іншому тижні.</i>";
        }

        return $text;
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
