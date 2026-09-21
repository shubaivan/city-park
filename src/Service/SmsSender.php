<?php

namespace App\Service;

use App\Entity\Account;
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
    /** TurboSMS, chosen on price at our volume: ~0.98 грн against AlphaSMS's 1.20. */
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
        $log = (new SmsLog($phone, $text, $purpose))
            ->setParts(self::parts($text))
            ->setSentBy($sentBy)
            ->setRecipient($account, $user);

        $key = $log->getPhoneKey();

        if ($key === '') {
            return $this->persist($log->markFailed('номер не схожий на телефон'));
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
            return $this->persist($log->markFailed('сьогодні вже надсилали на цей номер'));
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

            return $this->persist($log->markFailed($e->getMessage()));
        }

        $code = (int)($body['response_code'] ?? -1);
        $result = $body['response_result'][0] ?? [];

        // 0 and the 800s are the provider's success codes; everything else is a refusal,
        // and its own words are more useful in the journal than anything we could write.
        if ($code === 0 || ($code >= 800 && $code < 900)) {
            return $this->persist($log->markSent($result['message_id'] ?? null));
        }

        return $this->persist($log->markFailed(sprintf(
            '%s (%s)',
            $body['response_status'] ?? 'unknown error',
            $code,
        )));
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
}
