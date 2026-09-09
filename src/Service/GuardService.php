<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;

/**
 * What the guard on the gate needs, and nothing else.
 *
 * His job is one sentence: walk up to the people in the альтанка, ask who they are, and
 * check that against a list. Until now the list lived on the accountant's screen and in
 * the bot of the person who booked, so the check was «зателефонуйте Аліні» or nothing at
 * all — asked for on 07.09.2026 by Иван for «Охорона Ситипарк».
 *
 * Three deliberate limits:
 *
 * - **Today only, and mostly "now".** He is standing in front of the pavilion at 20:10;
 *   next Saturday is not his business, and a full schedule is a page to scroll on a phone
 *   in the dark. The board leads with what is running this minute and follows with the
 *   rest of the evening.
 * - **The flat, never a name or a phone.** «буд. 19, кв. 85» is exactly what the check
 *   needs — the person says which flat they are from and it either matches or it does
 *   not. The registry holds no owner names, and the phone in that row is there because
 *   the resident gave it to the ОСББ for нарахування, not so that the gate can ring it.
 *   (The same call the complaints register makes for the author's contact.)
 * - **Guards are Telegram ids in `.env.local`** (`GUARD_TELEGRAM_IDS`), same shape as
 *   COMPLAINT_MANAGER_TELEGRAM_IDS: one or two people who change about never. **An empty
 *   list means nobody, never everybody** — this board names which flat is where at what
 *   time, and a bug that opened it to the whole house would be a bug nobody could see.
 */
class GuardService
{
    /**
     * How long before a session starts the guard already sees it as «зараз».
     *
     * People arrive early and the guard walks up when he sees them, not when the clock
     * says 20:00. A board that answers «нікого не заброньовано» at 19:52 sends him to
     * move along the very people whose booking is about to start.
     */
    public const EARLY_MINUTES = 20;

    /**
     * And how long after it ends it stays on the board. They are still packing up, and
     * the pavilion photo is due within the hour — the guard telling them to leave while
     * they are photographing it is the one interaction this feature exists to prevent.
     */
    public const GRACE_MINUTES = 20;

    public function __construct(
        private ScheduledSetRepository $bookings,
        private string $guardIds = '',
        private string $appSecret = '',
    ) {}

    public function isGuard(?TelegramUser $user): bool
    {
        $id = $user?->getTelegramId();

        if ($id === null || $id === '') {
            return false;
        }

        return in_array((string)$id, $this->guardTelegramIds(), true);
    }

    /** @return string[] */
    public function guardTelegramIds(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->guardIds))));
    }

    public function isConfigured(): bool
    {
        return $this->guardTelegramIds() !== [];
    }

    /**
     * Every booking of the given Kyiv day, grouped into sessions.
     *
     * @return array<int, array{pavilion:int, start:\DateTimeImmutable, end:\DateTimeImmutable, account:?Account, user:TelegramUser}>
     */
    public function sessionsOfDay(\DateTimeInterface $day): array
    {
        $sets = $this->bookings->createQueryBuilder('ss')
            ->join('ss.telegramUserId', 'tu')
            ->andWhere('ss.year = :y')->setParameter('y', (int)$day->format('Y'))
            ->andWhere('ss.month = :m')->setParameter('m', (int)$day->format('n'))
            ->andWhere('ss.day = :d')->setParameter('d', (int)$day->format('j'))
            ->orderBy('ss.pavilion', 'ASC')
            ->addOrderBy('ss.hour', 'ASC')
            ->getQuery()
            ->getResult();

        return self::group($sets);
    }

    /**
     * Consecutive hours on one pavilion by one household are one session.
     *
     * Static and free of the database on purpose: this is the half with a rule in it —
     * «22:00 після 21:00 того самого мешканця» is the same evening, «22:00 після 20:00»
     * is two — and a rule that needs a database to exercise is a rule nobody tests.
     *
     * @param ScheduledSet[] $sets ordered by pavilion, then hour
     * @return array<int, array{pavilion:int, start:\DateTimeImmutable, end:\DateTimeImmutable, account:?Account, user:TelegramUser}>
     */
    public static function group(array $sets): array
    {
        $sessions = [];
        $current = null;

        foreach ($sets as $set) {
            $user = $set->getTelegramUserId();
            $account = $user->getAccount();
            $start = \DateTimeImmutable::createFromMutable($set->getScheduledDateTime());
            $end = $start->modify('+1 hour');
            $who = $account?->getId() ?? ('u' . $user->getId());

            if (
                $current !== null
                && $current['pavilion'] === $set->getPavilion()
                && $current['who'] === $who
                && $current['end']->getTimestamp() === $start->getTimestamp()
            ) {
                $current['end'] = $end;

                continue;
            }

            if ($current !== null) {
                $sessions[] = $current;
            }

            $current = [
                'pavilion' => $set->getPavilion(),
                'start' => $start,
                'end' => $end,
                'account' => $account,
                'user' => $user,
                'who' => $who,
            ];
        }

        if ($current !== null) {
            $sessions[] = $current;
        }

        return array_map(static function (array $s): array {
            unset($s['who']);

            return $s;
        }, $sessions);
    }

    /**
     * Is this session the one the guard is looking at right now?
     *
     * Widened at both ends — see EARLY_MINUTES / GRACE_MINUTES.
     *
     * @param array{start:\DateTimeImmutable, end:\DateTimeImmutable, ...} $session
     */
    public static function isRunning(array $session, \DateTimeInterface $now): bool
    {
        $from = $session['start']->modify('-' . self::EARLY_MINUTES . ' minutes');
        $until = $session['end']->modify('+' . self::GRACE_MINUTES . ' minutes');

        return $now->getTimestamp() >= $from->getTimestamp()
            && $now->getTimestamp() < $until->getTimestamp();
    }

    /**
     * @param array{end:\DateTimeImmutable, ...} $session
     */
    public static function isOver(array $session, \DateTimeInterface $now): bool
    {
        return $now->getTimestamp() >= $session['end']->modify('+' . self::GRACE_MINUTES . ' minutes')->getTimestamp();
    }

    /**
     * The session this household is in the middle of right now, if any.
     *
     * Read on every main-menu render to decide whether to offer «🔒 QR для охорони», and
     * again when a guard scans one. Owner-group aware, like every other booking query:
     * a flat and its parking space are one household and one person's phone.
     *
     * @return array{pavilion:int, start:\DateTimeImmutable, end:\DateTimeImmutable, account:?Account, user:TelegramUser}|null
     */
    public function runningSessionFor(Account $account, \DateTimeInterface $now): ?array
    {
        $sets = $this->bookings->createQueryBuilder('ss')
            ->join('ss.telegramUserId', 'tu')
            ->join('tu.account', 'a')
            ->andWhere('ss.year = :y')->setParameter('y', (int)$now->format('Y'))
            ->andWhere('ss.month = :m')->setParameter('m', (int)$now->format('n'))
            ->andWhere('ss.day = :d')->setParameter('d', (int)$now->format('j'))
            ->andWhere('COALESCE(a.owner_group_id, a.id) = :gid')
            ->setParameter('gid', $account->getEffectiveGroupId())
            ->orderBy('ss.pavilion', 'ASC')
            ->addOrderBy('ss.hour', 'ASC')
            ->getQuery()
            ->getResult();

        foreach (self::group($sets) as $session) {
            if (self::isRunning($session, $now)) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Who may hold a QR code, and who may read one: **any confirmed resident**.
     *
     * Both sides started narrower and both were opened by Иван on 09.09.2026 — the code
     * was a booking ticket only its holder's guard could read, and it is now the house's
     * own way of answering «а ти хто?». Two guards cannot perform that check for 457
     * people, and a pass that exists for three hours a month is a pass nobody remembers.
     *
     * **A block is deliberately irrelevant on both sides** — debt, a missed pavilion
     * photo, an admin's hand or a vote of the house. Every one of them decides whether
     * somebody may *book the альтанка*; none of them decides whether they live here, and
     * this code says nothing else. Withholding it would turn the pass into a public
     * statement that its holder owes money, made to whichever neighbour scanned them.
     * `GuardBoardRulesTest` pins it, because «зробити консистентно з бронюванням» is a
     * tempting and wrong tidy-up.
     *
     * An unlinked visitor is out of both: the bot has no flat for them, so there is
     * nothing to mint and nothing to be told. Same line the debtors' board and the
     * complaints register draw.
     */
    public function mayHoldQr(?Account $account): bool
    {
        return $account instanceof Account;
    }

    /** @see mayHoldQr() — one rule, both ends of the same code. */
    public function mayScan(?TelegramUser $user, ?Account $viewerAccount): bool
    {
        return $this->isGuard($user) || $viewerAccount instanceof Account;
    }

    /**
     * The payload behind the resident's QR code: `g-<account>-<signature>`.
     *
     * **Signed, and deliberately not stored.** The особові рахунки of the largest debtors
     * are published to the whole house every month, so a code that merely named a flat
     * could be drawn by anyone who read the board — and a guard would then confirm it,
     * because the flat really does have a booking. The HMAC is what makes the bot the
     * only thing that can mint one. Nothing is written to the database: what the guard is
     * asking is «is this household in the альтанка *now*», and that answer lives in the
     * bookings table already.
     *
     * The code therefore does not expire and does not need to: it says nothing on its own,
     * and the bot answers about the current minute or refuses.
     */
    public function mintToken(Account $account): string
    {
        $id = (int)$account->getId();

        return sprintf('g-%d-%s', $id, $this->signature($id));
    }

    /**
     * @return int|null the account id, or null when the payload is not one of ours
     */
    public function readToken(string $payload): ?int
    {
        if (!preg_match('/^g-(\d{1,10})-([a-f0-9]{12})$/', $payload, $m)) {
            return null;
        }

        $id = (int)$m[1];

        // hash_equals, not ===: a plain comparison on a signature leaks how much of it was
        // right through how long it took to say no.
        return hash_equals($this->signature($id), $m[2]) ? $id : null;
    }

    private function signature(int $accountId): string
    {
        return substr(hash_hmac('sha256', 'guard-qr:' . $accountId, $this->appSecret), 0, 12);
    }

    /**
     * The one line that answers «хто це». The building is never dropped: five buildings
     * repeat their apartment numbers, so «кв. 85» names two flats and the guard cannot
     * tell which of them is standing in front of him.
     *
     * @param array{account:?Account, ...} $session
     */
    public static function place(array $session): string
    {
        return $session['account']?->getPlaceLabel() ?? '❓ без особового рахунку';
    }
}
