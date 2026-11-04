<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\ExpectedResident;
use App\Entity\SmsLog;
use App\Entity\SmsRun;
use App\Entity\TelegramUser;
use App\Repository\SmsLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sending an SMS, and writing down that we did.
 *
 * The house has 531 objects with a debt and the bot can reach 83 of them; everybody else
 * is only reachable by phone. So this exists — but it exists as the **last** channel, not
 * the first: Telegram and the residents' chat are free and this one is not, and a channel
 * that costs money per person is one the ОСББ will switch off the first time it produces
 * a bill nobody expected.
 *
 * Every rule below is about that, or about the fact that an SMS cannot be unsent.
 */
class SmsSender
{
    /** TurboSMS: 1.29 грн a part on 30.09.2026 (1.28 from a 10 000 грн top-up, 1.26 from 50 000). */
    public const PRICE_PER_PART = 1.29;

    /**
     * The ОСББ's account number at TurboSMS — what a terminal asks for to top it up.
     * Not a secret: it lets anybody *add* money, nothing else, and it is exactly what the
     * person paying has to type (/admin/sms prints it under the balance).
     */
    public const ACCOUNT_ID = '8980641';

    /** The same-day guard's refusal; DebtSmsCampaign counts it as skipped, not as failed. */
    public const ALREADY_SENT = 'сьогодні вже надсилали на цей номер';

    /**
     * Journal prefix for a send whose outcome we never learned: a timeout can follow a
     * message TurboSMS accepted and billed. The same-day guard treats it like a sent one,
     * because a resident missing a reminder until tomorrow is cheaper than a paid duplicate.
     */
    public const OUTCOME_UNKNOWN = 'невідомо, чи пішло: ';

    private const SEND_URL = 'https://api.turbosms.ua/message/send.json';
    private const BALANCE_URL = 'https://api.turbosms.ua/user/balance.json';
    private const STATUS_URL = 'https://api.turbosms.ua/message/status.json';

    /**
     * Codes from the table at turbosms.ua/api.html that mean nothing was created for this SMS:
     * no token or an inactive user (103-106), a missing sender, text, recipient or balance
     * (200-203), an invalid request (300-306, 315), a sender, date, stoplist, recipient count or
     * country not allowed (400-406), content refused (411-413), the limits (428, 436-438) and a
     * body it could not read (502). Viber-only codes cannot answer an SMS and are left out: a
     * code not here is unknown, on purpose.
     */
    private const REFUSALS = [
        103, 104, 105, 106,
        200, 201, 202, 203,
        300, 301, 302, 303, 304, 305, 306, 315,
        400, 401, 403, 404, 405, 406, 411, 412, 413,
        428, 436, 437, 438,
        502,
    ];

    /**
     * «Попытка создать сообщение, которое уже было создано в течение последних 90 секунд». This
     * request created nothing, but the identical message exists and was billed, so the number
     * has been written to and must not be written to again today.
     */
    private const IDENTICAL_MESSAGE_EXISTS = 427;

    /**
     * TurboSMS asks for no timeout on a send: it processes the request anyway, and whoever gave
     * up then retries and pays twice. Here giving up never retries (OUTCOME_UNKNOWN blocks the
     * number), and a long wait now holds only the SMS worker, so a slow answer gets every chance
     * to be an answer. It stays finite for a connection that hung for good, and below the 150 s
     * city-park-sms.service gives a job to finish on restart, so a deploy does not cut a send off.
     */
    private const SEND_TIMEOUT = 120;

    /**
     * The circuit-breaker: requests that may cost money in one Kyiv day, from every path, 250 ×
     * 1.29 = 322.50 грн at most. Prod has at most 213 usable numbers, so a mailing to everybody
     * fits with a few tests to spare, and no failure can spend more than that in a day. Change it
     * with SMS_DAILY_LIMIT, which is the setting; this default is for code built by hand.
     */
    public const DEFAULT_DAILY_LIMIT = 250;

    public const DAILY_LIMIT_REACHED = 'досягнуто денного ліміту SMS: сьогодні більше не надсилаємо';

    public const STOPPED_ON_UNKNOWN = 'розсилку зупинено: невідомо, чи пішла попередня SMS. Перевірте кабінет TurboSMS і запустіть решту ще раз';

    /** Refusals about the account, not this number: asking for the next one gets the same answer. */
    private const REFUSALS_THAT_STOP_A_RUN = [103, 105, 106, 200, 301, 302, 400, 401, 436, 437];
    private const REFUSAL_NO_MONEY = 203;

    /**
     * A Cyrillic SMS is **70 characters**, not 160 — the alphabet does not fit GSM-7 and
     * the message goes out as UCS-2. Past that it is split, and a two-part message costs
     * twice. Every text this bot sends is written to fit one part, and `parts()` is what
     * makes an overflow visible instead of quietly doubling the bill.
     */
    public const CYRILLIC_SINGLE = 70;
    public const CYRILLIC_CONCAT = 67;
    public const LATIN_SINGLE = 160;
    public const LATIN_CONCAT = 153;

    public function __construct(
        private HttpClientInterface $http,
        private EntityManagerInterface $em,
        private SmsLogRepository $logs,
        private LoggerInterface $smsLogger,
        private string $token = '',
        private string $sender = '',
        private int $dailyLimit = self::DEFAULT_DAILY_LIMIT,
    ) {
    }

    /**
     * Is there a token at all?
     *
     * Kept separate from sending so a caller can say «SMS не налаштовано» instead of
     * producing a row of failures that look like the provider refused us.
     */
    public function isConfigured(): bool
    {
        return trim($this->token) !== '';
    }

    /** The sender name as the recipient will see it, or '' while none is registered. */
    public function senderName(): string
    {
        return trim($this->sender);
    }

    /**
     * How many SMS the provider will charge for this text.
     *
     * Any Cyrillic character puts the whole message into UCS-2 — one Ukrainian «і» in an
     * otherwise Latin text costs the same as a wholly Ukrainian one, which is exactly the
     * trap a «just add the address in English» edit walks into.
     */
    public static function parts(string $text): int
    {
        $length = mb_strlen($text);
        if ($length === 0) {
            return 0;
        }

        $cyrillic = (bool)preg_match('/[^\x00-\x7F]/u', $text);
        $single = $cyrillic ? self::CYRILLIC_SINGLE : self::LATIN_SINGLE;
        $concat = $cyrillic ? self::CYRILLIC_CONCAT : self::LATIN_CONCAT;

        return $length <= $single ? 1 : (int)ceil($length / $concat);
    }

    /** What this text will cost, at the tariff the account is actually on. */
    public static function cost(string $text, float $pricePerPart): float
    {
        return round(self::parts($text) * $pricePerPart, 2);
    }

    /** Would the same-day guard in send() stop this number? Lets a plan price it out up front. */
    public function alreadySentToday(string $phone, string $purpose): bool
    {
        $key = PhoneKey::of($phone);

        return $key !== '' && $this->logs->alreadySentToday($key, $purpose);
    }

    /**
     * Send one message, and return the journal row either way.
     *
     * **Never throws.** A resident who cannot be reached must not stop the loop reaching
     * the next one — the failure is a row with its reason in it, which is the whole point
     * of having a journal. The only thing that stops a send before it starts is having no
     * number, no token, or having already written to this person today.
     */
    public function send(
        string $phone,
        string $text,
        string $purpose,
        ?Account $account = null,
        ?TelegramUser $user = null,
        ?string $sentBy = null,
        bool $dryRun = false,
    ): SmsLog {
        // A number typed by hand (sms:test) still belongs to somebody. Without this the
        // journal shows a bare phone, and «кому це пішло» is answered over SSH again.
        if ($account === null) {
            [$account, $user] = $this->recipientByPhone($phone, $user);
        }

        $log = (new SmsLog($phone, $text, $purpose))
            ->setParts(self::parts($text))
            ->setSentBy($sentBy)
            ->setRecipient($account, $user);

        $invalid = $this->invalidReason($log, $phone, $text);
        if ($invalid !== null) {
            return $this->persist($log->markFailed($invalid));
        }

        if ($dryRun) {
            return $this->persist($log->markDryRun());
        }

        $notNow = $this->notNowReason($log);
        if ($notNow !== null) {
            return $this->persist($log->markFailed($notNow));
        }

        return $this->persist($this->post($log, null));
    }

    /**
     * The row of a queued job, committed before anything leaves: `sending`, or failed with why.
     *
     * Unlike everything else here this one throws. A row that could not be written means the
     * job has no way to know next time that it already sent, so it must not send now.
     */
    public function claim(string $phone, string $text, string $purpose, ?Account $account, ?string $sentBy, SmsRun $run): SmsLog
    {
        $log = (new SmsLog($phone, $text, $purpose))
            ->setParts(self::parts($text))
            ->setSentBy($sentBy)
            ->setRecipient($account, null)
            ->setRun($run);

        $refused = $this->invalidReason($log, $phone, $text) ?? $this->notNowReason($log);
        $refused === null ? $log->markSending() : $log->markFailed($refused);

        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    /**
     * The request for a claimed row. `$sequenceId` makes TurboSMS run it at most once in 30 days
     * and answer a repeat with 507 and the first result. A second line only: a job with a row
     * never comes back here.
     *
     * Throws if the answer cannot be written. The row stays `sending`, which blocks the number,
     * and the job must end up where a person can see it rather than count as done.
     */
    public function sendClaimed(SmsLog $log, string $sequenceId): SmsLog
    {
        $this->post($log, $sequenceId);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    /**
     * A queued job's row saying why it did not send. Throws like claim(): a job whose row could
     * not be written must not be acknowledged as if it had one.
     */
    public function writeOff(string $phone, string $text, string $purpose, ?Account $account, ?string $sentBy, string $reason, SmsRun $run): SmsLog
    {
        $log = (new SmsLog($phone, $text, $purpose))
            ->setParts(self::parts($text))
            ->setSentBy($sentBy)
            ->setRecipient($account, null)
            ->setRun($run)
            ->markFailed($reason);

        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    /**
     * Why this run must not go on after this answer, or null. A refusal about the account would
     * come back for every job left. An unknown outcome is the first sign of a provider that hangs:
     * minutes a job, and maybe a bill per number with nothing to show.
     * The rest then write their rows without asking.
     */
    public static function reasonToStopRun(SmsLog $log): ?string
    {
        $error = (string)$log->getError();

        if ($log->isFailed() && str_starts_with($error, self::OUTCOME_UNKNOWN)) {
            return self::STOPPED_ON_UNKNOWN;
        }

        if ($log->isFailed() && $error === self::DAILY_LIMIT_REACHED) {
            return self::DAILY_LIMIT_REACHED;
        }

        if (!$log->isFailed() || preg_match('/\((\d+)\)$/', $error, $code) !== 1) {
            return null;
        }

        if ((int)$code[1] === self::REFUSAL_NO_MONEY) {
            return DebtSmsCampaign::OUT_OF_MONEY;
        }

        return in_array((int)$code[1], self::REFUSALS_THAT_STOP_A_RUN, true) ? 'TurboSMS відмовив: ' . $error . ', розсилку зупинено' : null;
    }

    /** What is wrong with the message itself. */
    private function invalidReason(SmsLog $log, string $phone, string $text): ?string
    {
        if ($log->getPhoneKey() === '') {
            return 'номер не схожий на телефон';
        }

        // A foreign number is refused outright rather than "helpfully" converted. The
        // conversion below puts 380 in front of the last nine digits, which for the
        // `+7 959` and `+48` numbers really on prod produces a *different, real*
        // Ukrainian subscriber — somebody who would receive a stranger's debt and flat
        // number. Refusing costs one resident an SMS; guessing costs the ОСББ an
        // apology it cannot make, because it would not know it had happened.
        if (!PhoneKey::isUkrainian($phone)) {
            return 'не український номер — SMS не надсилаємо';
        }

        // One part, always. A second part doubles what the run costs, and Иван's call on
        // 30.09.2026 was that it is never worth it («дві — це занадто жирно»). A text that
        // does not fit is refused here rather than sent, so a template that grows past 70
        // characters shows up as rows of failures in the journal — and in a dry run, before
        // anything is spent — instead of as a doubled bill.
        if ($log->getParts() > 1) {
            return sprintf('текст на %d SMS — надсилаємо лише одну (%d символів)', $log->getParts(), mb_strlen($text));
        }

        return null;
    }

    /** What stops an otherwise good message right now. */
    private function notNowReason(SmsLog $log): ?string
    {
        if (!$this->isConfigured()) {
            return 'TURBOSMS_TOKEN не налаштовано';
        }

        // Same-day repeat guard. The expensive mistake is not a wrong number, it is the
        // same right number written to twice because a cron ran again or an import was
        // re-uploaded after a correction.
        if ($this->logs->alreadySentToday($log->getPhoneKey(), $log->getPurpose())) {
            return self::ALREADY_SENT;
        }

        // The last gate before any request, on every path: panel, console and sms:test.
        return $this->logs->costlyToday() >= $this->dailyLimit ? self::DAILY_LIMIT_REACHED : null;
    }

    /**
     * The request itself, and what it came to. One attempt, whatever happens: no retry here and
     * none in the client, and redirects off, so a 307 cannot post it twice.
     */
    private function post(SmsLog $log, ?string $sequenceId): SmsLog
    {
        $phone = $log->getPhone();
        $started = microtime(true);
        $raw = null;
        $http = null;
        $body = [];

        try {
            $response = $this->http->request('POST', self::SEND_URL, [
                'headers' => ['Authorization' => 'Bearer ' . trim($this->token)],
                'json' => array_filter([
                    'sequence_id' => $sequenceId,
                    'recipients' => [$this->msisdn($phone)],
                    'sms' => array_filter([
                        'sender' => $this->senderName() !== '' ? $this->senderName() : null,
                        'text' => $log->getText(),
                    ]),
                ]),
                'timeout' => self::SEND_TIMEOUT,
                'max_redirects' => 0,
            ]);

            $http = $response->getStatusCode();
            $raw = $response->getContent(false);
            $body = $response->toArray(false);
            $this->recordAnswer($log, $body, $phone);
        } catch (\Throwable $e) {
            $log->markFailed(self::OUTCOME_UNKNOWN . $e->getMessage());
        }

        $this->smsLogger->info('sms answer', [
            'row' => $log->getId(),
            'run' => $log->getRun()?->getId(),
            'phone_key' => $log->getPhoneKey(),
            'http' => $http,
            'code' => $body['response_code'] ?? null,
            'status' => $body['response_status'] ?? null,
            'message_id' => $log->getProviderId(),
            'decision' => self::decisionOf($log),
            'error' => $log->getError(),
            'duration_ms' => (int)((microtime(true) - $started) * 1000),
            'raw' => self::forTheLog($raw),
        ]);

        return $log;
    }

    private static function decisionOf(SmsLog $log): string
    {
        return match (true) {
            $log->isSent() => 'sent',
            str_starts_with((string)$log->getError(), self::OUTCOME_UNKNOWN) => 'unknown',
            default => 'refused',
        };
    }

    /**
     * A provider's answer as it came, for the log: nobody has seen a real one yet. Its numbers
     * cut down to the nine digits the log goes by, and the length bounded.
     */
    private static function forTheLog(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $masked = preg_replace_callback('/(?<![\w-])\+?\d{9,15}(?![\w-])/', static fn (array $m): string => substr($m[0], -9), $raw);

        return mb_substr((string)$masked, 0, 2000);
    }

    /**
     * What TurboSMS said about this one recipient, written down as what it means for the money.
     *
     * **The answer for the recipient decides, not the answer for the request.** A request with
     * a refused recipient comes back 802, SUCCESS_MESSAGE_PARTIAL_ACCEPTED, with that
     * recipient's message_id null and its own code in the entry (turbosms.ua/api.html).
     *
     * Three outcomes, and the third is why this is not a yes or no. Sent: an id. Refused: the
     * docs say the request stops there, listed in REFUSALS, and a later attempt is fine. Unknown:
     * everything else, a code nobody listed included. A billed message must not look refused and
     * go out again, so those take OUTCOME_UNKNOWN, which the same-day guard treats like a sent one.
     *
     * @param array<mixed> $body
     */
    private function recordAnswer(SmsLog $log, array $body, string $phone): SmsLog
    {
        $code = is_numeric($body['response_code'] ?? null) ? (int)$body['response_code'] : null;
        if ($code === null) {
            return $log->markFailed(self::OUTCOME_UNKNOWN . 'відповідь без коду результату');
        }

        $status = self::statusText($body);
        $entry = self::entryFor($body, $phone);

        if ($entry !== null) {
            $id = is_scalar($entry['message_id'] ?? null) && (string)$entry['message_id'] !== '' ? (string)$entry['message_id'] : null;
            $entryCode = is_numeric($entry['response_code'] ?? null) ? (int)$entry['response_code'] : null;
            $entryStatus = self::statusText($entry);
            $entryAccepted = $entryCode === null || self::isAccepted($entryCode);

            if ($id !== null) {
                return $entryAccepted && self::isAccepted($code)
                    ? $log->markSent($id)
                    : $log->markFailed(self::OUTCOME_UNKNOWN . sprintf('є id, але код %s (%d)', $entryStatus, $entryCode ?? $code));
            }

            if (!$entryAccepted) {
                return self::recordCode($log, $entryCode, $entryStatus);
            }
        }

        if (self::isAccepted($code)) {
            return $log->markFailed(self::OUTCOME_UNKNOWN . sprintf('TurboSMS прийняв запит (%s, %d), але не дав id', $status, $code));
        }

        return self::recordCode($log, $code, $status);
    }

    /** Not accepted, no id: refused only if the docs say this code stops the request. */
    private static function recordCode(SmsLog $log, int $code, string $status): SmsLog
    {
        if ($code === self::IDENTICAL_MESSAGE_EXISTS) {
            return $log->markFailed(self::OUTCOME_UNKNOWN . sprintf('таке саме повідомлення вже створено за останні 90 секунд, %s (%d)', $status, $code));
        }

        return in_array($code, self::REFUSALS, true)
            ? $log->markFailed(sprintf('%s (%d)', $status, $code))
            : $log->markFailed(self::OUTCOME_UNKNOWN . sprintf('%s (%d)', $status, $code));
    }

    /**
     * OK, the four «created or sent» answers to message/send, and the answer to a repeated
     * sequence_id, which carries the results of the request that already ran.
     */
    private static function isAccepted(int $code): bool
    {
        return $code === 0 || ($code >= 800 && $code <= 803) || $code === 507;
    }

    /** @param array<mixed> $answer */
    private static function statusText(array $answer): string
    {
        return is_scalar($answer['response_status'] ?? null) ? (string)$answer['response_status'] : 'unknown error';
    }

    /**
     * This recipient's entry in response_result. One recipient per request here, so a single
     * entry is theirs even if the provider wrote the number another way.
     *
     * @param array<mixed> $body
     *
     * @return array<mixed>|null
     */
    private static function entryFor(array $body, string $phone): ?array
    {
        $entries = array_values(array_filter(
            is_array($body['response_result'] ?? null) ? $body['response_result'] : [],
            'is_array',
        ));

        foreach ($entries as $entry) {
            if (is_scalar($entry['phone'] ?? null) && PhoneKey::same((string)$entry['phone'], $phone)) {
                return $entry;
            }
        }

        return count($entries) === 1 ? $entries[0] : null;
    }

    /**
     * A journal row for a message that was deliberately not sent — never reaches TurboSMS.
     *
     * For a run stopped half-way (the balance ran out): the households after that point
     * get a row saying why, so «чому мені не прийшло» is answered by the journal rather
     * than by a gap in it.
     */
    public function refuse(string $phone, string $text, string $purpose, ?Account $account, ?string $sentBy, string $reason): SmsLog
    {
        $log = (new SmsLog($phone, $text, $purpose))
            ->setParts(self::parts($text))
            ->setSentBy($sentBy)
            ->setRecipient($account, null);

        return $this->persist($log->markFailed($reason));
    }

    /**
     * The account balance, or null when it cannot be asked.
     *
     * Null is not zero and must not be rendered as «0 грн»: one means «we have no money»,
     * the other means «we do not know», and only the first is a reason to stop.
     */
    public function balance(): ?float
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $body = $this->http->request('GET', self::BALANCE_URL, [
                'headers' => ['Authorization' => 'Bearer ' . trim($this->token)],
                'timeout' => 10,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->smsLogger->warning('sms balance check failed', ['error' => $e->getMessage()]);

            return null;
        }

        $balance = $body['response_result']['balance'] ?? null;

        return $balance === null ? null : (float)$balance;
    }

    /**
     * What TurboSMS last said about this message, or null when it could not say: no connection,
     * an unreadable answer, or a code in place of a status (414 for an id it does not know).
     * Never throws. This is the one request here that cannot send anything.
     */
    public function deliveryStatus(string $providerId): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $started = microtime(true);
        $raw = null;
        $body = [];

        try {
            $response = $this->http->request('POST', self::STATUS_URL, [
                'headers' => ['Authorization' => 'Bearer ' . trim($this->token)],
                'json' => ['messages' => [$providerId]],
                'timeout' => 10,
            ]);
            $raw = $response->getContent(false);
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->smsLogger->warning('sms delivery status check failed', ['message_id' => $providerId, 'error' => $e->getMessage(), 'raw' => self::forTheLog($raw)]);

            return null;
        }

        $status = null;
        foreach (is_array($body['response_result'] ?? null) ? $body['response_result'] : [] as $entry) {
            if (is_array($entry) && ($entry['message_id'] ?? null) === $providerId && is_string($entry['status'] ?? null) && $entry['status'] !== '') {
                $status = $entry['status'];
            }
        }

        $this->smsLogger->info('sms status answer', [
            'message_id' => $providerId,
            'code' => $body['response_code'] ?? null,
            'delivery_status' => $status,
            'duration_ms' => (int)((microtime(true) - $started) * 1000),
            'raw' => self::forTheLog($raw),
        ]);

        return $status;
    }

    /**
     * International format without a plus, which is what the provider wants.
     *
     * A number stored as `0932729951` is the same phone as `+380 93 272 99 51`, and the
     * registry holds both shapes — PhoneKey already knows that, so the country code is
     * put back on its nine digits rather than guessed from the prefix.
     *
     * Only ever reached for a number `PhoneKey::isUkrainian()` has already accepted: on
     * anything else this line silently invents a Ukrainian subscriber out of a foreign
     * one. See the guard in send().
     */
    private function msisdn(string $phone): string
    {
        return '380' . PhoneKey::of($phone);
    }

    private function persist(SmsLog $log): SmsLog
    {
        try {
            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable $e) {
            // A journal that cannot be written must not swallow a message that was sent.
            $this->smsLogger->error('sms log write failed', ['error' => $e->getMessage()]);
        }

        return $log;
    }

    /**
     * Whose number this is: a linked resident first, then an object that expects it.
     *
     * @return array{0: ?Account, 1: ?TelegramUser}
     */
    private function recipientByPhone(string $phone, ?TelegramUser $user): array
    {
        $key = PhoneKey::of($phone);
        if ($key === '') {
            return [null, $user];
        }

        try {
            /** @var TelegramUser|null $resident */
            $resident = $this->em->getRepository(TelegramUser::class)->createQueryBuilder('tu')
                ->andWhere('tu.account IS NOT NULL')
                ->andWhere('tu.phone_number LIKE :tail')->setParameter('tail', '%' . $key)
                ->orderBy('tu.id', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($resident instanceof TelegramUser) {
                return [$resident->getAccount(), $user ?? $resident];
            }

            $expected = $this->em->getRepository(ExpectedResident::class)->findOneBy(['phone_key' => $key]);

            return [$expected?->getAccount(), $user];
        } catch (\Throwable $e) {
            $this->smsLogger->warning('SMS: could not resolve recipient by phone', ['error' => $e->getMessage()]);

            return [null, $user];
        }
    }
}
