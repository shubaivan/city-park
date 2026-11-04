<?php

namespace App\Service;

use App\Command\DebtNotifySmsCommand;
use App\Entity\Account;
use App\Entity\SmsLog;
use App\Entity\SmsRun;
use App\Entity\TelegramUser;
use App\Message\DebtSmsMessage;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;
use App\Repository\SmsRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Who a debt SMS run would reach, and the run itself.
 *
 * One place for both the console command and the panel's button (Людмила, 07.10.2026 —
 * «кнопка, яка відправить SMS усім, у кого борг більше 5 тис., з телефона»). Two copies of
 * "who counts as a debtor with a number" is how the button and the command end up
 * disagreeing about who was written to.
 *
 * `plan()` and `estimate()` decide and spend nothing; `send()` sends exactly what a plan
 * holds, which is the console's path. The button goes through `sendConfirmed()`, which is where
 * a double send is refused and which only queues: one job per number, sent by the worker
 * (DebtSmsDelivery), so the tap returns at once and a run survives a closed tab.
 *
 * In the panel a run goes only to the addresses ticked (Иван, 04.11.2026). What the browser
 * sends is a wish, not a list: `narrow()` holds it against a fresh `plan()` at every step.
 *
 * The table on the card is the debtors above the threshold; the search above it reaches every
 * debtor (Иван, 04.11.2026). `estimate()` plans for all of them once, and the threshold's list
 * is cut out of that, so the two cannot disagree about who a number belongs to.
 */
class DebtSmsCampaign
{
    use LogsWithoutThrowing;

    /** Иван's and the ОСББ's figure: above this a debt is worth paying to chase. */
    public const DEFAULT_MIN_DEBT = 5000.0;

    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_UNREACHABLE = 'unreachable';

    /** What sendConfirmed() did. Anything but QUEUED means not one job was queued. */
    public const OUTCOME_QUEUED = 'queued';
    public const OUTCOME_RUNNING = 'running';
    public const OUTCOME_CHANGED = 'changed';
    public const OUTCOME_BLOCKED = 'blocked';

    public function __construct(
        private AccountRepository $accounts,
        private ExpectedResidentRepository $expected,
        private SmsSender $sms,
        private LoggerInterface $smsLogger,
        private string $projectDir,
        private SmsRunRepository $runs,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private SmsDeliveryChecker $deliveryChecks,
    ) {
    }

    /**
     * Who a run would write to, and everybody it would not, with the reason.
     *
     * Anybody SmsSender would refuse is taken out here rather than left to fail, so that
     * the recipients, and therefore the cost, are what will really go out.
     *
     * @return array{
     *     recipients: list<array{account: Account, phone: string, text: string}>,
     *     tooLong: list<Account>,
     *     noPhone: int,
     *     skippedTelegram: int,
     *     invalidPhone: list<array{account: Account, phone: string, text: string}>,
     *     alreadySent: list<array{account: Account, phone: string, text: string}>,
     *     samePhone: list<array{account: Account, phone: string, text: string}>,
     *     debtors: int,
     *     rows: list<array{account: Account, phone: ?string, parts: int, reason: ?string}>,
     * }
     */
    public function plan(float $min = self::DEFAULT_MIN_DEBT, string $audience = self::AUDIENCE_ALL): array
    {
        $plan = [
            'recipients' => [], 'tooLong' => [], 'noPhone' => 0, 'skippedTelegram' => 0,
            'invalidPhone' => [], 'alreadySent' => [], 'samePhone' => [], 'debtors' => 0,
            'rows' => [],
        ];

        // Every debtor, one row each: who can be ticked in the panel, and why the rest cannot.
        $row = static function (Account $account, ?string $phone, ?string $text, ?string $reason) use (&$plan): void {
            $plan['rows'][] = [
                'account' => $account,
                'phone' => $phone,
                'parts' => $text === null ? 0 : SmsSender::parts($text),
                'reason' => $reason,
            ];
        };

        $debtors = array_filter(
            // With the owners and, below, the phones in one query each: the search lists about
            // 900 debtors on every view of the page, and this was two queries for each of them.
            $this->accounts->findAllWithOwners(),
            static fn ($account) => $account instanceof Account && (float)($account->getDebt() ?? 0) >= $min,
        );
        // Largest debt first: it is the order anybody reading the list wants it in, and when
        // one number covers two objects it decides which of them the one SMS names.
        // Equal debts by the unique особовий рахунок, so the choice survives how they were loaded.
        usort($debtors, static fn (Account $a, Account $b) => [(float)$b->getDebt(), $a->getAccountNumber()] <=> [(float)$a->getDebt(), $b->getAccountNumber()]);

        $plan['debtors'] = count($debtors);
        $seen = [];
        $expectedPhones = $this->expected->phonesByAccount();

        foreach ($debtors as $account) {
            if ($audience === self::AUDIENCE_UNREACHABLE && $this->reachableInTelegram($account)) {
                $plan['skippedTelegram']++;
                $row($account, null, null, self::REASON_TELEGRAM);
                continue;
            }

            $phone = $this->phoneFor($account, $expectedPhones[$account->getId()] ?? []);
            if ($phone === null) {
                $plan['noPhone']++;
                $row($account, null, null, self::REASON_NO_PHONE);
                continue;
            }

            $text = DebtNotifySmsCommand::text($account);
            if ($text === null) {
                $plan['tooLong'][] = $account;
                $row($account, $phone, null, self::REASON_TOO_LONG);
                continue;
            }

            $recipient = ['account' => $account, 'phone' => $phone, 'text' => $text];
            $key = PhoneKey::of($phone);

            if (!PhoneKey::isUkrainian($phone)) {
                $plan['invalidPhone'][] = $recipient;
                $row($account, $phone, $text, self::REASON_FOREIGN);
            } elseif (isset($seen[$key])) {
                // The same-day guard would refuse the second one anyway; it must not be priced.
                $plan['samePhone'][] = $recipient;
                $row($account, $phone, $text, self::REASON_SAME_PHONE);
            } elseif ($this->sms->alreadySentToday($phone, SmsLog::PURPOSE_DEBT)) {
                $plan['alreadySent'][] = $recipient;
                $row($account, $phone, $text, self::REASON_ALREADY_SENT);
            } else {
                $seen[$key] = true;
                $plan['recipients'][] = $recipient;
                $row($account, $phone, $text, null);
            }
        }

        return $plan;
    }

    /**
     * The plan with its price and the one reason, if any, that it may not go now.
     *
     * Asked on every page view and again at the tap. `$balance` is the caller's, since the
     * journal page has already asked TurboSMS for it. Spends nothing, writes nothing.
     *
     * With `$selectedIds` everything is about those addresses only: the recipients, the
     * cost, the balance check. `unavailable` is the reason nobody can be sent to right now
     * whatever gets ticked, which is what greys out the button on the card.
     *
     * The figures and the rows are the threshold's, as on the console. `searchable` is every
     * debtor, and an id from there may be selected like one from the table: it passes the
     * same checks, against the same plan.
     *
     * @param list<mixed>|null $selectedIds account ids as they came from the browser
     *
     * @return array<string, mixed> plan() plus searchable, parts, partsPerMessage, cost, balance, configured, fingerprint, blocked, unavailable, rejected, notChosen, fromSearch, skippedSummary
     */
    public function estimate(?float $balance, ?\DateTimeImmutable $now = null, ?array $selectedIds = null): array
    {
        $now ??= new \DateTimeImmutable();
        $everyone = $this->plan((float)AccountRepository::MIN_PUBLISHED_DEBT);
        $plan = self::aboveThreshold($everyone);
        $plan['searchable'] = $everyone['rows'];
        $plan['skippedSummary'] = self::skippedBreakdown($plan);
        $running = $this->runs->findActive($now) !== null;
        $plan['running'] = $running;
        $plan['unavailable'] = $running ? self::RUN_IN_PROGRESS : $this->unavailableReason($everyone['recipients'] !== [], $balance, $now);
        $plan['selection'] = $selectedIds !== null;
        $plan = $selectedIds === null
            ? $plan + ['rejected' => [], 'notChosen' => 0, 'fromSearch' => 0]
            : self::narrow($plan, $everyone['recipients'], $selectedIds);

        $parts = array_map(static fn (array $r) => SmsSender::parts($r['text']), $plan['recipients']);

        $plan['parts'] = array_sum($parts);
        $plan['partsPerMessage'] = $parts === [] ? 0 : max($parts);
        $plan['cost'] = round($plan['parts'] * SmsSender::PRICE_PER_PART, 2);
        $plan['balance'] = $balance;
        $plan['configured'] = $this->sms->isConfigured();
        $plan['fingerprint'] = self::fingerprint($plan);
        $plan['blocked'] = $this->blockedReason($plan, $now);

        return $plan;
    }

    /**
     * Exactly who gets exactly what. The confirmation page carries it, and the send refuses
     * if it no longer matches: a debt file uploaded in between, or the same form sent twice,
     * since whoever a run reached has left the list.
     *
     * @param array{recipients: list<array{account: Account, phone: string, text: string}>} $plan
     */
    public static function fingerprint(array $plan): string
    {
        $lines = array_map(
            static fn (array $r) => PhoneKey::of($r['phone']) . '|' . $r['text'],
            $plan['recipients'],
        );
        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * The window the bot already keeps quiet in (PowerScheduleAnnouncer). A Telegram post
     * can go out silently at night; an SMS cannot, so here it does not go out at all.
     */
    public function quietHoursReason(?\DateTimeImmutable $now = null): ?string
    {
        $hour = (int)($now ?? new \DateTimeImmutable())
            ->setTimezone(new \DateTimeZone('Europe/Kyiv'))
            ->format('G');

        if ($hour < PowerScheduleAnnouncer::QUIET_FROM && $hour >= PowerScheduleAnnouncer::QUIET_UNTIL) {
            return null;
        }

        return sprintf(
            'Зараз тихі години (%d:00–%02d:00 за Києвом): SMS вночі не надсилаємо. Спробуйте після %02d:00.',
            PowerScheduleAnnouncer::QUIET_FROM,
            PowerScheduleAnnouncer::QUIET_UNTIL,
            PowerScheduleAnnouncer::QUIET_UNTIL,
        );
    }

    /**
     * Only one run at a time, from the panel or the console.
     *
     * The same-day guard reads the journal, so two runs started together would both find it
     * empty and both send. An flock is released by the kernel when the process dies, so a
     * crashed run cannot leave the button locked. Null means somebody holds it, or the file
     * cannot be opened: either way the caller must not send.
     *
     * @return resource|null
     */
    public function acquireLock(bool $wait = false)
    {
        $path = $this->projectDir . '/var/debt-sms.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            $this->logLine('error', 'debt sms: cannot open the lock file', ['path' => $path]);

            return null;
        }

        // The panel runs as www-data, the console may not; either must be able to open it.
        @chmod($path, 0666);

        if (!flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource|null $lock */
    public function releaseLock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The confirmed tap: take the lock, look at everything again, queue or refuse whole.
     *
     * Never a partial run by choice: if the balance does not cover the ticked addresses, or
     * one of them may no longer be written to, nothing goes.
     *
     * @param list<mixed> $selectedIds account ids as they came from the browser
     *
     * @return array{outcome: string, flash: string, message: string}
     */
    public function sendConfirmed(string $fingerprint, array $selectedIds, string $sentBy, ?\DateTimeImmutable $now = null): array
    {
        $lock = $this->acquireLock();
        if ($lock === null) {
            return $this->refused(
                self::OUTCOME_RUNNING,
                $sentBy,
                'Розсилка вже йде. Нічого додатково не надіслано — результат з\'явиться в журналі нижче.',
            );
        }

        try {
            $this->runs->closeStale($now ?? new \DateTimeImmutable());
            $estimate = $this->estimate($this->sms->balance(), $now, $selectedIds);

            if ($estimate['rejected'] !== []) {
                return $this->refused(self::OUTCOME_CHANGED, $sentBy, 'Нічого не надіслано. ' . self::rejectedReason($estimate['rejected']));
            }

            if ($estimate['blocked'] !== null) {
                return $this->refused(self::OUTCOME_BLOCKED, $sentBy, 'Нічого не надіслано. ' . $estimate['blocked']);
            }

            if (!hash_equals($estimate['fingerprint'], $fingerprint)) {
                return $this->refused(self::OUTCOME_CHANGED, $sentBy, sprintf(
                    'Список змінився: тепер %d адрес. Нічого не надіслано — перевірте і натисніть ще раз.',
                    count($estimate['recipients']),
                ));
            }

            $now ??= new \DateTimeImmutable();
            $run = new SmsRun($sentBy, count($estimate['recipients']), $now);

            // The jobs live in the same database: all of them and the run commit together, or none.
            $this->em->wrapInTransaction(function () use ($run, $estimate): void {
                $this->em->persist($run);
                $this->em->flush();

                foreach ($estimate['recipients'] as $r) {
                    $this->bus->dispatch(new DebtSmsMessage((int)$run->getId(), (int)$r['account']->getId(), $r['phone'], $r['text']));
                }
            });

            $this->logLine('info', 'debt sms: run queued', [
                'run' => $run->getId(), 'by' => $sentBy, 'recipients' => $run->getTotal(),
                'cost' => $estimate['cost'], 'balance' => $estimate['balance'],
            ]);

            return [
                'outcome' => self::OUTCOME_QUEUED,
                'flash' => 'success',
                'message' => sprintf(
                    'Поставлено в чергу: %d SMS · %s грн. Надсилаємо по одній, результат нижче й у журналі.',
                    $run->getTotal(),
                    number_format($estimate['cost'], 2, '.', ' '),
                ),
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Send what the plan holds, one journal row per recipient whatever happens.
     *
     * **Money running out half-way stops the run.** The balance is checked before the
     * first message, but it can still run out mid-way — somebody else spent it, or the
     * price changed — and TurboSMS then refuses every message after that one. Asking on to
     * the end would only write the same refusal fifty times; so after a failure the
     * balance is asked once, and if it cannot pay for one more SMS the rest are written
     * to the journal as not sent, with that reason. Pressing the button again after a
     * top-up is safe: the same-day guard in SmsSender skips everyone already reached.
     *
     * @param array{recipients: list<array{account: Account, phone: string, text: string}>, invalidPhone?: list<array{account: Account, phone: string, text: string}>} $plan
     *
     * @return array{sent: int, failed: int, skipped: int, parts: int, outOfMoney: bool, logs: list<SmsLog>}
     */
    public function send(array $plan, string $sentBy, bool $dryRun = false): array
    {
        $result = [
            'sent' => 0,
            'failed' => 0,
            'skipped' => self::skippedCount($plan),
            'parts' => 0,
            'outOfMoney' => false,
            'logs' => [],
        ];

        $limitReached = null;

        foreach ($plan['recipients'] as $r) {
            $stop = $result['outOfMoney'] ? self::OUT_OF_MONEY : $limitReached;
            if ($stop !== null) {
                $result['logs'][] = $this->sms->refuse(
                    $r['phone'],
                    $r['text'],
                    SmsLog::PURPOSE_DEBT,
                    $r['account'],
                    $sentBy,
                    $stop,
                );
                $result['failed']++;
                continue;
            }

            $log = $this->sms->send($r['phone'], $r['text'], SmsLog::PURPOSE_DEBT, $r['account'], null, $sentBy, $dryRun);

            $result['logs'][] = $log;

            // A send the plan could not foresee being guarded is a skip: nothing was spent.
            if ($log->isFailed() && $log->getError() === SmsSender::ALREADY_SENT) {
                $result['skipped']++;
                continue;
            }

            if ($log->isFailed() && $log->getError() === SmsSender::DAILY_LIMIT_REACHED) {
                $limitReached = SmsSender::DAILY_LIMIT_REACHED;
                $result['failed']++;
                continue;
            }

            if ($log->isFailed()) {
                $result['failed']++;

                if (!$dryRun) {
                    $balance = $this->sms->balance();
                    $result['outOfMoney'] = $balance !== null && $balance < SmsSender::PRICE_PER_PART;
                }
                continue;
            }

            $result['sent']++;
            $result['parts'] += $log->getParts();

            // The console's sends are in the same journal, so they get the same answer. Queued
            // for the worker; dry runs have nothing to ask about.
            if (!$dryRun) {
                $this->deliveryChecks->start($log);
            }
        }

        // A foreign number stays a journal row with its reason, as it always was.
        foreach ($plan['invalidPhone'] ?? [] as $r) {
            $result['logs'][] = $this->sms->refuse(
                $r['phone'],
                $r['text'],
                SmsLog::PURPOSE_DEBT,
                $r['account'],
                $sentBy,
                self::INVALID_PHONE,
            );
        }

        return $result;
    }

    /** «без номера 59, вже надіслано сьогодні 3» — only what actually happened. */
    public static function skippedBreakdown(array $plan): string
    {
        $reasons = array_filter([
            'без номера' => $plan['noPhone'] ?? 0,
            'номер не український' => count($plan['invalidPhone'] ?? []),
            'вже надіслано сьогодні або результат невідомий' => count($plan['alreadySent'] ?? []),
            'той самий номер, що в іншої адреси' => count($plan['samePhone'] ?? []),
            'адреса не влазить в одну SMS' => count($plan['tooLong'] ?? []),
            'є в Telegram' => $plan['skippedTelegram'] ?? 0,
        ]);

        return implode(', ', array_map(static fn (string $why, int $n) => $why . ' ' . $n, array_keys($reasons), $reasons));
    }

    private static function skippedCount(array $plan): int
    {
        return ($plan['noPhone'] ?? 0) + ($plan['skippedTelegram'] ?? 0) + count($plan['invalidPhone'] ?? [])
            + count($plan['alreadySent'] ?? []) + count($plan['samePhone'] ?? []) + count($plan['tooLong'] ?? []);
    }

    /**
     * The plan of every debtor cut down to the threshold's.
     *
     * The same as `plan()` at the threshold, not an approximation: a plan walks the largest
     * debt first, so whatever decided a row above the threshold had been seen by then in both.
     *
     * @param array<string, mixed> $everyone
     *
     * @return array<string, mixed>
     */
    private static function aboveThreshold(array $everyone): array
    {
        $above = static fn (Account $account): bool => (float)$account->getDebt() >= self::DEFAULT_MIN_DEBT;
        $recipient = static fn (array $r): bool => $above($r['account']);

        $rows = array_values(array_filter($everyone['rows'], $recipient));

        return [
            'recipients' => array_values(array_filter($everyone['recipients'], $recipient)),
            'tooLong' => array_values(array_filter($everyone['tooLong'], $above)),
            'noPhone' => count(array_filter($rows, static fn (array $r): bool => $r['reason'] === self::REASON_NO_PHONE)),
            'skippedTelegram' => count(array_filter($rows, static fn (array $r): bool => $r['reason'] === self::REASON_TELEGRAM)),
            'invalidPhone' => array_values(array_filter($everyone['invalidPhone'], $recipient)),
            'alreadySent' => array_values(array_filter($everyone['alreadySent'], $recipient)),
            'samePhone' => array_values(array_filter($everyone['samePhone'], $recipient)),
            'debtors' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * The plan cut down to what was ticked, and what was ticked but may not be written to.
     *
     * Ids are compared as strings, not cast: `12abc` must not quietly become address 12.
     * A rejected id is never dropped and the rest sent, since the person confirmed a list
     * and that list no longer exists. The same form sent twice lands here too: whoever the
     * first run reached is no longer a recipient.
     *
     * @param array<string, mixed>                                               $plan
     * @param list<array{account: Account, phone: string, text: string}>         $eligible everybody who may be written to, below the threshold included
     * @param list<mixed>                                                        $selectedIds
     *
     * @return array<string, mixed>
     */
    private static function narrow(array $plan, array $eligible, array $selectedIds): array
    {
        $wanted = array_values(array_unique(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string)$id : '',
            $selectedIds,
        )));

        $aboveThreshold = count($plan['recipients']);
        $plan['recipients'] = array_values(array_filter(
            $eligible,
            static fn (array $r): bool => in_array((string)$r['account']->getId(), $wanted, true),
        ));
        $plan['rejected'] = array_values(array_diff(
            $wanted,
            array_map(static fn (array $r): string => (string)$r['account']->getId(), $plan['recipients']),
        ));
        $plan['fromSearch'] = count(array_filter(
            $plan['recipients'],
            static fn (array $r): bool => (float)$r['account']->getDebt() < self::DEFAULT_MIN_DEBT,
        ));
        $plan['notChosen'] = $aboveThreshold - (count($plan['recipients']) - $plan['fromSearch']);

        return $plan;
    }

    /** @param list<string> $rejected */
    private static function rejectedReason(array $rejected): string
    {
        return sprintf(
            'Список змінився: вибраних адрес, яким SMS уже не можна надіслати: %d. Перевірте список і виберіть ще раз.',
            count($rejected),
        );
    }

    /** What stops every send, whoever is ticked. Cost is not here: that depends on the ticks. */
    private function unavailableReason(bool $anyRecipient, ?float $balance, ?\DateTimeImmutable $now): ?string
    {
        if (!$this->sms->isConfigured()) {
            return 'TurboSMS не налаштовано на сервері — надіслати нема чим.';
        }
        if (!$anyRecipient) {
            return 'Нікому надсилати.';
        }
        if ($balance === null) {
            return 'TurboSMS не відповів, скільки на рахунку. Без цього не надсилаємо — оновіть сторінку за кілька хвилин.';
        }

        return $this->quietHoursReason($now);
    }

    private function blockedReason(array $estimate, ?\DateTimeImmutable $now): ?string
    {
        if (!$estimate['configured']) {
            return 'TurboSMS не налаштовано на сервері — надіслати нема чим.';
        }
        if ($estimate['running']) {
            return self::RUN_IN_PROGRESS;
        }
        if ($estimate['rejected'] !== []) {
            return self::rejectedReason($estimate['rejected']);
        }
        if ($estimate['recipients'] === []) {
            return $estimate['selection'] ? 'Нікого не вибрано.' : 'Нікому надсилати.';
        }
        if ($estimate['balance'] === null) {
            return 'TurboSMS не відповів, скільки на рахунку. Без цього не надсилаємо — оновіть сторінку за кілька хвилин.';
        }
        if ($estimate['balance'] < $estimate['cost']) {
            return sprintf(
                'На рахунку TurboSMS %s грн, а розсилка коштує %s грн — бракує %s грн. Частково не надсилаємо: поповніть рахунок і оновіть сторінку.',
                number_format($estimate['balance'], 2, '.', ' '),
                number_format($estimate['cost'], 2, '.', ' '),
                number_format($estimate['cost'] - $estimate['balance'], 2, '.', ' '),
            );
        }

        return $this->quietHoursReason($now);
    }

    /** @return array{outcome: string, flash: string, message: string} */
    private function refused(string $outcome, string $sentBy, string $message): array
    {
        $this->logLine('info', 'debt sms: refused', ['by' => $sentBy, 'outcome' => $outcome, 'message' => $message]);

        return ['outcome' => $outcome, 'flash' => 'warning', 'message' => $message];
    }

    private const INVALID_PHONE = 'не український номер — SMS не надсилаємо';

    /** Why a row on the card cannot be ticked. */
    private const REASON_NO_PHONE = 'немає номера';
    private const REASON_FOREIGN = 'номер не український';
    private const REASON_ALREADY_SENT = 'сьогодні вже надсилали або результат невідомий';
    private const REASON_SAME_PHONE = 'той самий номер, що в іншої адреси';
    private const REASON_TOO_LONG = 'адреса не влазить в одну SMS';
    private const REASON_TELEGRAM = 'є в Telegram';

    /** Why the households after the point the money ran out were not written to. */
    public const OUT_OF_MONEY = 'на балансі TurboSMS закінчились кошти — розсилку зупинено';

    private const RUN_IN_PROGRESS = 'Розсилка вже йде. Дочекайтесь, поки вона закінчиться: результат на цій сторінці.';

    /** The flat's number as things stand now, by the rules of phoneFor(). For a job at delivery. */
    public function currentPhone(Account $account): ?string
    {
        return $this->phoneFor($account, $this->expected->phonesByAccount()[$account->getId()] ?? []);
    }

    /** Somebody on this account whom the bot can write to for free. */
    private function reachableInTelegram(Account $account): bool
    {
        foreach ($account->getUsers() as $user) {
            if ($user->getChatId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A number for this flat, from whichever half of the register has one.
     *
     * A linked resident's own number first — it was confirmed by them sharing it with the
     * bot — and otherwise whatever the ОСББ wrote down against the object, which is the
     * only thing there is for the ~790 objects with nobody in the bot.
     *
     * The earliest resident first, by id: a query without an order hands them back as the table
     * happens to lie, and the same-day guard is per number, so a flat whose number changed between
     * two runs could be written to twice.
     *
     * Never a tenant's: the flat's arrears are not theirs (`owesForTheFlat()`, as in
     * DebtNotifyCommand). That goes for the ОСББ's row too, which survives being claimed: one
     * claimed by a tenant, or carrying a linked tenant's number, is that tenant's.
     *
     * @param list<array{phone: string, claimedByRole: ?string}> $expected what the ОСББ wrote down for this object
     */
    private function phoneFor(Account $account, array $expected): ?string
    {
        $users = $account->getUsers()->toArray();
        usort($users, static fn (TelegramUser $a, TelegramUser $b): int => $a->getId() <=> $b->getId());

        $phones = [];
        $tenantKeys = [];
        foreach ($users as $user) {
            if (!$user->getPhoneNumber()) {
                continue;
            }
            if ($user->owesForTheFlat()) {
                $phones[] = $user->getPhoneNumber();
            } else {
                $tenantKeys[PhoneKey::of($user->getPhoneNumber())] = true;
            }
        }

        foreach ($expected as $row) {
            if ($row['claimedByRole'] !== TelegramUser::ROLE_TENANT && !isset($tenantKeys[PhoneKey::of($row['phone'])])) {
                $phones[] = $row['phone'];
            }
        }

        // A foreign number first in line must not hide a usable one behind it.
        foreach ($phones as $phone) {
            if (PhoneKey::isUkrainian($phone)) {
                return $phone;
            }
        }

        return $phones[0] ?? null;
    }
}
