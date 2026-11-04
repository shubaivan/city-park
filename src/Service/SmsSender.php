<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\ExpectedResident;
use App\Entity\SmsLog;
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
        private LoggerInterface $logger,
        private string $token = '',
        private string $sender = '',
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

        $key = $log->getPhoneKey();

        if ($key === '') {
            return $this->persist($log->markFailed('номер не схожий на телефон'));
        }

        // A foreign number is refused outright rather than "helpfully" converted. The
        // conversion below puts 380 in front of the last nine digits, which for the
        // `+7 959` and `+48` numbers really on prod produces a *different, real*
        // Ukrainian subscriber — somebody who would receive a stranger's debt and flat
        // number. Refusing costs one resident an SMS; guessing costs the ОСББ an
        // apology it cannot make, because it would not know it had happened.
        if (!PhoneKey::isUkrainian($phone)) {
            return $this->persist($log->markFailed('не український номер — SMS не надсилаємо'));
        }

        // One part, always. A second part doubles what the run costs, and Иван's call on
        // 30.09.2026 was that it is never worth it («дві — це занадто жирно»). A text that
        // does not fit is refused here rather than sent, so a template that grows past 70
        // characters shows up as rows of failures in the journal — and in a dry run, before
        // anything is spent — instead of as a doubled bill.
        if ($log->getParts() > 1) {
            return $this->persist($log->markFailed(sprintf(
                'текст на %d SMS — надсилаємо лише одну (%d символів)',
                $log->getParts(),
                mb_strlen($text),
            )));
        }

        if ($dryRun) {
            return $this->persist($log->markDryRun());
        }

        if (!$this->isConfigured()) {
            return $this->persist($log->markFailed('TURBOSMS_TOKEN не налаштовано'));
        }

        // Same-day repeat guard. The expensive mistake is not a wrong number, it is the
        // same right number written to twice because a cron ran again or an import was
        // re-uploaded after a correction.
        if ($this->logs->alreadySentToday($key, $purpose)) {
            return $this->persist($log->markFailed(self::ALREADY_SENT));
        }

        try {
            $response = $this->http->request('POST', self::SEND_URL, [
                'headers' => ['Authorization' => 'Bearer ' . trim($this->token)],
                'json' => [
                    'recipients' => [$this->msisdn($phone)],
                    'sms' => array_filter([
                        'sender' => $this->senderName() !== '' ? $this->senderName() : null,
                        'text' => $text,
                    ]),
                ],
                'timeout' => 15,
            ]);

            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('sms send failed', ['phone_key' => $key, 'error' => $e->getMessage()]);

            return $this->persist($log->markFailed(self::OUTCOME_UNKNOWN . $e->getMessage()));
        }

        return $this->persist($this->recordAnswer($log, $body, $phone));
    }

    /**
     * What TurboSMS said about this one recipient, written down as what it means for the money.
     *
     * **The answer for the recipient decides, not the answer for the request.** A request with
     * a refused recipient comes back 802, SUCCESS_MESSAGE_PARTIAL_ACCEPTED, with that
     * recipient's message_id null and its own code in the entry (turbosms.ua/api.html).
     *
     * Three outcomes, and the third is why this is not a yes or no. Sent: an id. Refused: the
     * provider says so, nothing was created, and a later attempt is fine. Unknown: anything that
     * says it was accepted without an id, or that cannot be read. A billed message must not
     * look refused and go out again, so those take OUTCOME_UNKNOWN, which the same-day guard
     * treats like a sent one.
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
                return $log->markFailed(sprintf('%s (%d)', $entryStatus, $entryCode));
            }
        }

        if (self::isAccepted($code)) {
            return $log->markFailed(self::OUTCOME_UNKNOWN . sprintf('TurboSMS прийняв запит (%s, %d), але не дав id', $status, $code));
        }

        // A result it could not encode, or its own fatal error: the request may well have run.
        if ($code === 500 || $code === 501 || ($code >= 9000 && $code <= 9999)) {
            return $log->markFailed(self::OUTCOME_UNKNOWN . sprintf('%s (%d)', $status, $code));
        }

        return $log->markFailed(sprintf('%s (%d)', $status, $code));
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
            $this->logger->warning('sms balance check failed', ['error' => $e->getMessage()]);

            return null;
        }

        $balance = $body['response_result']['balance'] ?? null;

        return $balance === null ? null : (float)$balance;
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
            $this->logger->error('sms log write failed', ['error' => $e->getMessage()]);
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
            $this->logger->warning('SMS: could not resolve recipient by phone', ['error' => $e->getMessage()]);

            return [null, $user];
        }
    }
}
