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
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class BookingHistory
{
    private const DAYS = 30;
    private const MESSAGE_CHAR_LIMIT = 3800;

    /** @var array<string, PavilionPhoto> account_id:pavilion:Y-m-d H:i => photo (covers each session) */
    private array $photoByHourKey = [];
    /** @var array<string, PhotoUploadRequest> */
    private array $reqByHourKey = [];
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
        $history = $this->schedulePavilionService->getHistory(self::DAYS);

        if (!$history) {
            $bot->sendMessage(
                text: '📜 <b>Історія бронювань за останні ' . self::DAYS . ' днів порожня</b>',
                parse_mode: ParseMode::HTML,
            );
            return;
        }

        $this->preloadStatusIndex($history);

        foreach ($this->renderMessages($history) as $msg) {
            $bot->sendMessage(text: $msg, parse_mode: ParseMode::HTML);
        }
    }

    /**
     * Build an index of {account+pavilion+hour => photo|request} for fast lookups while rendering.
     * @param array<string, ScheduledSet[]> $history
     */
    private function preloadStatusIndex(array $history): void
    {
        $this->obligationStart = $this->photoService->obligationStartAt();

        $earliest = null;
        $latest = null;
        foreach ($history as $sets) {
            foreach ($sets as $set) {
                $dt = $set->getScheduledDateTime();
                if ($earliest === null || $dt < $earliest) {
                    $earliest = clone $dt;
                }
                if ($latest === null || $dt > $latest) {
                    $latest = clone $dt;
                }
            }
        }
        if (!$earliest || !$latest) {
            return;
        }
        $earliest->modify('-1 day');
        $latest->modify('+1 day');

        $photos = $this->em->createQuery(
            'SELECT p, a FROM App\Entity\PavilionPhoto p JOIN p.account a '
            . 'WHERE p.session_start_at BETWEEN :from AND :until'
        )->setParameter('from', $earliest)->setParameter('until', $latest)->getResult();

        $requests = $this->em->createQuery(
            'SELECT r, a FROM App\Entity\PhotoUploadRequest r JOIN r.account a '
            . 'WHERE r.session_start_at BETWEEN :from AND :until AND r.resolved_at IS NULL'
        )->setParameter('from', $earliest)->setParameter('until', $latest)->getResult();

        /** @var PavilionPhoto $p */
        foreach ($photos as $p) {
            $this->indexBySessionHours(
                $this->photoByHourKey,
                $p->getAccount()->getId(),
                $p->getPavilion(),
                $p->getSessionStartAt(),
                $p->getSessionEndAt(),
                $p,
            );
        }
        /** @var PhotoUploadRequest $r */
        foreach ($requests as $r) {
            $this->indexBySessionHours(
                $this->reqByHourKey,
                $r->getAccount()->getId(),
                $r->getPavilion(),
                $r->getSessionStartAt(),
                $r->getSessionEndAt(),
                $r,
            );
        }
    }

    private function indexBySessionHours(array &$bag, int $accountId, int $pavilion, \DateTimeInterface $start, \DateTimeInterface $end, object $value): void
    {
        $cursor = (clone $start);
        $endTs = $end->getTimestamp();
        while ($cursor->getTimestamp() < $endTs) {
            $key = sprintf('%d:%d:%s', $accountId, $pavilion, $cursor->format('Y-m-d H:i'));
            $bag[$key] = $value;
            $cursor->modify('+1 hour');
        }
    }

    /**
     * @param array<string, ScheduledSet[]> $history
     * @return string[]
     */
    private function renderMessages(array $history): array
    {
        $header = '📜 <b>Історія бронювань за ' . self::DAYS . " днів:</b>\n";
        $messages = [];
        $current = $header;

        foreach ($history as $sets) {
            $first = $sets[0]->getScheduledDateTime();
            $section = "\n📅 <b>" . UkDateFormatter::dayDate($first) . "</b>\n";
            foreach ($sets as $set) {
                $section .= $this->formatEntry($set);
            }

            if (strlen($current) + strlen($section) > self::MESSAGE_CHAR_LIMIT && $current !== '') {
                $messages[] = $current;
                $current = '';
            }
            $current .= $section;
        }

        if ($current !== '') {
            $messages[] = $current;
        }

        return $messages;
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
