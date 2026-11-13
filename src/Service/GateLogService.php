<?php

namespace App\Service;

use App\Entity\GateEvent;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Repository\CarPlateRepository;
use App\Repository\GateEventRepository;
use App\Repository\GuestPassRepository;
use App\Repository\QrScanRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * The gate's log of who came in and who went out (Иван, 13.11.2026).
 *
 * After a **valid** scan by the **guard**, the answer carries two buttons, «🚗 В'їзд» and
 * «🚙 Виїзд». The press writes one `GateEvent`: who, when, which guard, which way, the flat
 * and the car. Everybody else's scan is exactly what it was — no buttons, nothing written.
 *
 * The button is bound three ways, and each is checked when it is pressed:
 *
 * - **to the scan** — `gate:<in|out>:<scan id>:<sig>`, and the event is unique per scan, so a
 *   double tap or a re-delivered callback records once and answers «вже зафіксовано»;
 * - **to the guard who scanned** — the signature is an HMAC over the scan id and *his*
 *   Telegram id, and the scan row must name him as its reader. A forwarded message, or
 *   anybody else's thumb, is refused; so is a guard whose id has left GUARD_TELEGRAM_IDS;
 * - **to the moment** — after TTL_MINUTES the button is stale: the car has long gone one
 *   way or the other, and a press now would be a guess written down as a fact.
 */
class GateLogService
{
    public const TTL_MINUTES = 10;

    public const NOOP_CALLBACK = 'gate:noop';

    /** Routed in config/telegram.php; GateWiringTest holds the two in step. */
    public const CALLBACK_PATTERN = '^gate:(?:(?:in|out):\d+:[a-f0-9]{10}|noop)$';

    public function __construct(
        private EntityManagerInterface $em,
        private GateEventRepository $events,
        private QrScanRepository $scans,
        private GuestPassRepository $passes,
        private CarPlateRepository $plates,
        private GuardService $guard,
        private string $appSecret = '',
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Did this scan let somebody through? A resident's code is valid with or without a
     * booking («✅ Код дійсний» either way); a guest pass only inside its window.
     */
    public static function isPassable(QrScan $scan): bool
    {
        return match ($scan->getKind()) {
            QrScan::KIND_RESIDENT => in_array($scan->getResult(), [QrScan::RESULT_OK, QrScan::RESULT_NO_BOOKING], true),
            QrScan::KIND_GUEST => $scan->getResult() === QrScan::RESULT_OK,
            default => false,
        };
    }

    /**
     * The two buttons for this scan, or null when it gets none: not a guard, not a valid
     * scan, or the scan row was never written (then there is nothing to bind them to).
     *
     * @return InlineKeyboardButton[]|null
     */
    public function buttonsFor(?QrScan $scan, ?TelegramUser $reader): ?array
    {
        $telegramId = (string)$reader?->getTelegramId();

        if ($scan === null || $scan->getId() === null || !$this->guard->isGuard($reader) || !self::isPassable($scan)) {
            return null;
        }

        $sig = $this->sign((int)$scan->getId(), $telegramId);

        return [
            InlineKeyboardButton::make(
                GateEvent::labelFor(GateEvent::DIRECTION_IN),
                callback_data: sprintf('gate:%s:%d:%s', GateEvent::DIRECTION_IN, $scan->getId(), $sig),
            ),
            InlineKeyboardButton::make(
                GateEvent::labelFor(GateEvent::DIRECTION_OUT),
                callback_data: sprintf('gate:%s:%d:%s', GateEvent::DIRECTION_OUT, $scan->getId(), $sig),
            ),
        ];
    }

    /**
     * @return array{direction:string, scanId:int, sig:string}|null
     */
    public static function parse(string $data): ?array
    {
        if (!preg_match('/^gate:(in|out):(\d{1,10}):([a-f0-9]{10})$/', $data, $m)) {
            return null;
        }

        return ['direction' => $m[1], 'scanId' => (int)$m[2], 'sig' => $m[3]];
    }

    public function sign(int $scanId, string $telegramId): string
    {
        return substr(hash_hmac('sha256', 'gate:' . $scanId . ':' . $telegramId, $this->appSecret), 0, 10);
    }

    /** One press. Every refusal is decided before anything is written. */
    public function press(string $data, ?TelegramUser $caller, \DateTimeInterface $now): GatePress
    {
        $parsed = self::parse($data);

        if ($parsed === null) {
            return new GatePress(GatePress::INVALID);
        }

        $callerId = (string)$caller?->getTelegramId();

        // Bound to the guard: his id in the signature, his id in the list right now.
        if (
            $callerId === ''
            || !hash_equals($this->sign($parsed['scanId'], $callerId), $parsed['sig'])
            || !$this->guard->isGuard($caller)
        ) {
            return new GatePress(GatePress::FORBIDDEN);
        }

        $scan = $this->scans->find($parsed['scanId']);

        if (!$scan instanceof QrScan || !self::isPassable($scan) || !$scan->isByGuard()) {
            return new GatePress(GatePress::INVALID, scan: $scan);
        }

        // And bound to the scan: the row must name him as the one who scanned it.
        if ((string)$scan->getScannedBy()?->getTelegramId() !== $callerId) {
            return new GatePress(GatePress::FORBIDDEN, scan: $scan);
        }

        // Idempotent before stale: a guard re-tapping a decided button is told what he
        // decided, whenever he taps.
        $existing = $this->events->forScan((int)$scan->getId());

        if ($existing !== null) {
            return new GatePress(GatePress::ALREADY, $existing, $scan);
        }

        if ($now->getTimestamp() - $scan->getCreatedAt()->getTimestamp() > self::TTL_MINUTES * 60) {
            return new GatePress(GatePress::EXPIRED, scan: $scan);
        }

        $event = $this->describe($scan, $parsed['direction'], $caller);

        try {
            $this->em->persist($event);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two taps in the same instant; the index decided. The winner is the record.
            return new GatePress(GatePress::ALREADY, scan: $scan);
        }

        $this->logger->info('gate event recorded', [
            'scan_id' => $scan->getId(),
            'direction' => $event->getDirection(),
            'account_id' => $scan->getSubjectAccount()?->getId(),
        ]);

        return new GatePress(GatePress::RECORDED, $event, $scan);
    }

    /** The event a press would write, labels resolved now. */
    public function describe(QrScan $scan, string $direction, ?TelegramUser $guard): GateEvent
    {
        $account = $scan->getSubjectAccount();
        $pass = $scan->getGuestPassId() !== null ? $this->passes->find($scan->getGuestPassId()) : null;

        if ($pass instanceof GuestPass) {
            $person = '👷 ' . $pass->getLabel();
            $car = null; // a pass's label already leads with the car number, if it has one
        } else {
            $names = [];

            foreach ($account?->getUsers() ?? [] as $user) {
                $name = GuardService::contactName($user);

                if ($name !== '—' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }

                if (count($names) >= 3) {
                    break;
                }
            }

            $person = $names === [] ? '—' : implode(', ', $names);
            $car = $account !== null
                ? implode(', ', array_map(static fn ($p) => $p->getDisplay(), $this->plates->forAccount($account)))
                : null;
        }

        return (new GateEvent())
            ->setDirection($direction)
            ->setQrScan($scan)
            ->setKind($scan->getKind())
            ->setGuard($guard)
            ->setGuardLabel(GuestPassService::personLabel($guard, true))
            ->setSubjectAccount($account)
            ->setPlaceLabel($scan->getSubjectLabel() !== '' ? $scan->getSubjectLabel() : ($account?->getPlaceLabel() ?? '—'))
            ->setPersonLabel($person)
            ->setCarLabel($car)
            ->setGuestPassId($scan->getGuestPassId());
    }

    /**
     * «Зафіксовано: 🚗 В'їзд — Олена Петренко, буд. 19, кв. 85, авто CA 1234 AB, 14:32».
     * HTML-escaped, for `parse_mode: HTML`.
     */
    public static function confirmation(GateEvent $event): string
    {
        $parts = [$event->getPersonLabel(), $event->getPlaceLabel()];

        if ($event->getCarLabel() !== null) {
            $parts[] = 'авто ' . $event->getCarLabel();
        }

        $parts[] = $event->getCreatedAt()->format('H:i');

        return sprintf(
            '✅ <b>Зафіксовано: %s</b> — %s',
            $event->getDirectionLabel(),
            htmlspecialchars(implode(', ', $parts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /** @return GateEvent[] */
    public function ofDay(\DateTimeInterface $day): array
    {
        return $this->events->ofDay($day);
    }
}
