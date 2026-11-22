<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Repository\GuestPassRepository;
use App\Repository\QrScanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Passes for the people a flat lets in, and the log of every code anybody scans.
 *
 * All the judgement of the feature lives here; the Telegram halves only draw it.
 *
 * The shape, and why (09.09.2026, Иван):
 *
 * - **A pass lives for a window, and the window is switched on.** He asked for «только на
 *   сегодня», which is right — a pass that lives a month circulates, and a lost screenshot
 *   stays a key. But minting a fresh code every morning would mean *re-sending the picture
 *   to the бригадир every morning*, and on the third day they stop looking at it. So the
 *   picture is made once, forwarded once, and `activate()` turns it on for today. Since
 *   18.11.2026 a window can also be whole days, up to a week (`activateDays()`): a crew
 *   on a five-day job no longer needs the flat to press a button every morning.
 * - **The scan log is not optional.** It was asked for in the same breath as opening the
 *   scan to the house, and it is what makes that defensible: «хто їх пустив і коли вони
 *   заходили» has no other answer anywhere in this system.
 */
class GuestPassService
{
    public function __construct(
        private GuestPassRepository $passes,
        private QrScanRepository $scans,
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private LoggerInterface $logger,
        private string $appSecret = '',
    ) {}

    /**
     * This pass's scans, newest first — the flat's own answer to «коли прийшли робітники».
     *
     * @return \App\Entity\QrScan[]
     */
    public function scansOf(GuestPass $pass, int $limit = 5): array
    {
        return $this->scans->forPass((int)$pass->getId(), $limit);
    }

    /** @return GuestPass[] */
    public function liveFor(Account $account): array
    {
        return $this->passes->liveFor($account);
    }

    /**
     * How many of the flat's passes count against GuestPass::MAX_PER_ACCOUNT: the live ones,
     * less those dormant for GuestPass::DORMANT_AFTER_DAYS.
     */
    public function countedFor(Account $account, ?\DateTimeInterface $now = null): int
    {
        $now ??= new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));

        return count(array_filter(
            $this->liveFor($account),
            static fn (GuestPass $pass): bool => !$pass->isDormantAt($now),
        ));
    }

    /**
     * Room for one more?
     *
     * Only creation is capped: switching a dormant pass on again is always allowed, even if
     * that takes the flat over the cap for a while. The button is drawn either way and this
     * is what its tap checks (and create() again), so a full flat is told why instead of
     * finding the button gone.
     */
    public function mayCreate(?Account $account): bool
    {
        return $account instanceof Account
            && $this->countedFor($account) < GuestPass::MAX_PER_ACCOUNT;
    }

    /** «1 діючий пропуск», «3 діючі пропуски», «10 діючих пропусків». */
    public static function livePasses(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return $n . ' ' . match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'діючий пропуск',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'діючі пропуски',
            default => 'діючих пропусків',
        };
    }

    /**
     * The day windows a resident can pick, in calendar days counting today, and what the
     * button calls each. «Сьогодні» first: it is the default, and what every pass was until
     * 18.11.2026. Every one of them ends at 23:59 Kyiv on its last day.
     */
    public const DAY_OPTIONS = [
        1 => 'Сьогодні',
        2 => 'Сьогодні й завтра',
        3 => '3 дні',
        7 => 'Тиждень',
    ];

    /**
     * `$days` is how many calendar days, counting today, the pass runs: 1 (the default) is
     * until 23:59 tonight, as every pass was before 18.11.2026; 7 is the outer limit.
     */
    public function create(Account $account, ?TelegramUser $issuedBy, string $label, int $days = 1): ?GuestPass
    {
        if (!$this->mayCreate($account)) {
            return null;
        }

        $pass = (new GuestPass())
            ->setAccount($account)
            ->setIssuedBy($issuedBy)
            ->setLabel($label);

        // Switched on the moment it is made: somebody creating a pass is standing next to
        // the crew, or expects them this week. A shorter window is one tap away on the card.
        $pass->setActiveUntil(self::endOfDays($days));

        $this->em->persist($pass);
        $this->em->flush();

        return $pass;
    }

    /**
     * Turn it on for whole days: until 23:59 of today, tomorrow, … up to GuestPass::MAX_DAYS.
     *
     * Whatever is asked is clamped to 1..MAX_DAYS — the number arrives in a callback, and a
     * hand-made `pass:days:<id>:365` must not mint a year-long key. Idempotent, and it may
     * shorten as well as lengthen: «Тиждень» pressed on Friday for a crew that finished
     * early is corrected with «Сьогодні» or «⏹ Вимкнути зараз».
     */
    public function activateDays(GuestPass $pass, int $days): void
    {
        if ($pass->isRevoked()) {
            return;
        }

        $pass->setActiveUntil(self::endOfDays($days));
        $this->em->flush();
    }

    /**
     * 23:59:59 Kyiv on the last of `$days` calendar days, counting the day of `$now`.
     *
     * Built on the wall clock in Europe/Kyiv, so a week across the October or March clock
     * change still ends at 23:59 local, not at 22:59 or 00:59. `$now` in any other zone is
     * read as the Kyiv day it falls on.
     */
    public static function endOfDays(int $days, ?\DateTimeInterface $now = null): \DateTime
    {
        $days = max(1, min(GuestPass::MAX_DAYS, $days));
        $kyiv = new \DateTimeZone('Europe/Kyiv');

        $end = $now === null
            ? new \DateTime('now', $kyiv)
            : \DateTime::createFromInterface($now)->setTimezone($kyiv);

        $end->setTime(23, 59, 59);

        if ($days > 1) {
            $end->modify(sprintf('+%d days', $days - 1));
        }

        return $end;
    }

    /**
     * How the end of an active window is said to people: «сьогодні до 14:20» on its own day,
     * «до 24.11 о 23:59» when it runs past today. The date is what matters on a multi-day
     * pass, and «до 23:59» with no day would read as tonight.
     */
    public static function untilLabel(GuestPass $pass, ?\DateTimeInterface $now = null): string
    {
        $until = $pass->getActiveUntil();

        if ($until === null) {
            return '';
        }

        $kyiv = new \DateTimeZone('Europe/Kyiv');
        $today = ($now === null ? new \DateTime('now', $kyiv) : \DateTime::createFromInterface($now)->setTimezone($kyiv))->format('Y-m-d');
        $until = \DateTime::createFromInterface($until)->setTimezone($kyiv);

        return $until->format('Y-m-d') === $today
            ? 'сьогодні до ' . $until->format('H:i')
            : 'до ' . $until->format('d.m о H:i');
    }

    /** Does the window run past tonight — a pass switched on for more than one day? */
    public static function runsPastToday(GuestPass $pass, ?\DateTimeInterface $now = null): bool
    {
        $until = $pass->getActiveUntil();

        return $until !== null && $until > self::endOfDays(1, $now);
    }

    /**
     * Turn it on — for a few hours, or until the end of the day.
     *
     * A delivery is two hours and a renovation is all day, and «до кінця дня» handed to a
     * courier is a key they keep until midnight. `$hours` null means the rest of today.
     *
     * **Never past midnight**, whatever is asked: four hours at 22:00 clamps to 23:59. An
     * hour window that quietly runs into tomorrow would be a second day nobody chose;
     * more than one day is asked for explicitly, with activateDays(). On a pass already
     * switched on for a week, an hour window *replaces* it — the card says so. Idempotent:
     * pressing a button twice is what people do, and the second press moves the deadline.
     */
    public function activate(GuestPass $pass, ?int $hours = null): void
    {
        if ($pass->isRevoked()) {
            return;
        }

        $endOfDay = self::endOfDay();

        if ($hours === null) {
            $pass->setActiveUntil($endOfDay);
        } else {
            $until = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
            $until->modify(sprintf('+%d hours', max(1, $hours)));

            $pass->setActiveUntil($until > $endOfDay ? $endOfDay : $until);
        }

        $this->em->flush();
    }

    /**
     * Switch it off now.
     *
     * The delivery came and went at 11:20; leaving the window open until midnight is the
     * thing this feature exists to avoid. Not a revocation — tomorrow the same picture
     * works again.
     */
    public function deactivate(GuestPass $pass): void
    {
        $pass->setActiveUntil(null);
        $this->em->flush();
    }

    /** 23:59 today, Kyiv — the outer limit of every hour window. */
    private static function endOfDay(): \DateTime
    {
        return self::endOfDays(1);
    }

    /** Dead for good — the picture in somebody's phone stops working. */
    public function revoke(GuestPass $pass): void
    {
        $pass->revoke();
        $this->em->flush();
    }

    /**
     * The payload behind a guest pass: `p-<id>-<signature>`.
     *
     * Signed like the resident's own code, and for the same reason: an id on its own could
     * be guessed, and a guard would then confirm somebody nobody invited. The row is what
     * carries the day and the revocation; the signature is what makes the bot the only
     * thing that can mint one.
     */
    public function mintToken(GuestPass $pass): string
    {
        $id = (int)$pass->getId();

        return sprintf('p-%d-%s', $id, $this->signature($id));
    }

    /** @return int|null the pass id, or null when the payload is not one of ours */
    public function readToken(string $payload): ?int
    {
        if (!preg_match('/^p-(\d{1,10})-([a-f0-9]{12})$/', $payload, $m)) {
            return null;
        }

        $id = (int)$m[1];

        // hash_equals, not ===: a plain comparison leaks how much of a signature was right
        // through how long it took to say no.
        return hash_equals($this->signature($id), $m[2]) ? $id : null;
    }

    public function find(int $id): ?GuestPass
    {
        return $this->passes->find($id);
    }

    private function signature(int $passId): string
    {
        return substr(hash_hmac('sha256', 'guest-pass:' . $passId, $this->appSecret), 0, 12);
    }

    /**
     * Write one scan into the log.
     *
     * **Never fatal.** The person is standing at the gate waiting for an answer; a log that
     * cannot be written must not turn into a refusal. Failures go to the log file instead.
     *
     * Returns the row, so the guard's «В'їзд / Виїзд» buttons can be bound to this very scan
     * (GateLogService); null when it could not be written — then there are no buttons.
     */
    public function recordScan(
        string $kind,
        string $result,
        ?TelegramUser $scannedBy,
        ?Account $subject,
        ?GuestPass $pass = null,
        bool $byGuard = false,
        ?TelegramUser $subjectUser = null,
    ): ?QrScan {
        try {
            $scan = (new QrScan())
                ->setKind($kind)
                ->setResult($result)
                ->setScannedBy($scannedBy)
                ->setScannedByLabel(self::personLabel($scannedBy, $byGuard))
                ->setSubjectAccount($subject)
                ->setSubjectLabel($subject?->getPlaceLabel() ?? '—')
                ->setSubjectUser($subjectUser)
                ->setSubjectUserLabel($subjectUser !== null ? GuardService::personLabel($subjectUser) : '')
                ->setGuestPassId($pass?->getId())
                ->setByGuard($byGuard);

            // Asked before the row is written, or the row it is asking about is its own.
            $first = $pass !== null
                && $result === QrScan::RESULT_OK
                && !$this->scans->passWasScannedToday((int)$pass->getId(), $scan->getCreatedAt());

            if ($pass !== null) {
                $pass->countScan($scan->getCreatedAt());
            }

            $this->em->persist($scan);
            $this->em->flush();

            if ($first) {
                $this->notifyArrival($pass, $scan);
            }

            return $scan;
        } catch (\Throwable $e) {
            $this->logger->warning('qr scan not recorded', [
                'kind' => $kind,
                'result' => $result,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Tell the flat its people are here.
     *
     * The first valid scan of the day is the crew arriving, and that is the fact the host
     * actually wants — «тем самым будет знать когда пришли строители» (Иван). Once a day,
     * because a guard who checks the same people twice at the door has not made them arrive
     * twice, and a second message teaches the resident to mute the first.
     *
     * Who scanned is deliberately not named: «охорона» or «мешканець» is the whole of what
     * the host needs, and naming the neighbour who checked turns a security tool into
     * something people avoid using.
     *
     * Never fatal, and never on the gate's critical path: the person at the door already
     * has their answer by the time this runs.
     */
    private function notifyArrival(GuestPass $pass, QrScan $scan): void
    {
        $chatId = $pass->getIssuedBy()?->getChatId();

        if ($chatId === null || $chatId === '') {
            return;
        }

        try {
            $this->bot->sendMessage(
                text: sprintf(
                    "👷 <b>Ваш пропуск щойно перевірили</b>\n\n%s\n%s о <b>%s</b>\n\n"
                        . '<i>Схоже, ваші люди на місці.</i>%s',
                    htmlspecialchars($pass->getLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $scan->isByGuard() ? 'Перевірила охорона' : 'Перевірив мешканець',
                    $scan->getCreatedAt()->format('H:i'),
                    // A pass switched on for days says so, once a day, in the message the
                    // flat actually reads — the reminder that it is still open.
                    self::runsPastToday($pass, $scan->getCreatedAt())
                        ? "\n<i>Пропуск діє " . self::untilLabel($pass, $scan->getCreatedAt()) . '.</i>'
                        : '',
                ),
                chat_id: (int)$chatId,
                parse_mode: ParseMode::HTML,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('guest pass arrival notice failed', [
                'pass_id' => $pass->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * «Ніка (буд. 19, кв. 85)», «Охорона · Сергій» — a snapshot, so the row still reads
     * like a person after the FK is nulled.
     */
    public static function personLabel(?TelegramUser $user, bool $byGuard = false): string
    {
        if ($user === null) {
            return $byGuard ? 'Охорона' : '—';
        }

        $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: ('id ' . $user->getTelegramId());
        $where = $user->getAccount()?->getPlaceLabel();

        return trim(sprintf(
            '%s%s%s',
            $byGuard ? 'Охорона · ' : '',
            $name,
            $where === null ? '' : ' (' . $where . ')',
        ));
    }
}
