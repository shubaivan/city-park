<?php

namespace App\Service;

use App\Command\DebtNotifySmsCommand;
use App\Entity\Account;
use App\Entity\SmsLog;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;

/**
 * Who a debt SMS run would reach, and the run itself.
 *
 * One place for both the console command and the panel's button (Людмила, 07.10.2026 —
 * «кнопка, яка відправить SMS усім, у кого борг більше 5 тис., з телефона»). Two copies of
 * "who counts as a debtor with a number" is how the button and the command end up
 * disagreeing about who was written to.
 *
 * `plan()` decides and spends nothing; `send()` sends exactly what a plan holds, so the
 * figure shown before the tap is the figure that goes out.
 */
class DebtSmsCampaign
{
    /** Иван's and the ОСББ's figure: above this a debt is worth paying to chase. */
    public const DEFAULT_MIN_DEBT = 5000.0;

    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_UNREACHABLE = 'unreachable';

    public function __construct(
        private AccountRepository $accounts,
        private ExpectedResidentRepository $expected,
        private SmsSender $sms,
    ) {
    }

    /**
     * @return array{
     *     recipients: list<array{account: Account, phone: string, text: string}>,
     *     tooLong: list<Account>,
     *     noPhone: int,
     *     skippedTelegram: int,
     * }
     */
    public function plan(float $min = self::DEFAULT_MIN_DEBT, string $audience = self::AUDIENCE_ALL): array
    {
        $plan = ['recipients' => [], 'tooLong' => [], 'noPhone' => 0, 'skippedTelegram' => 0];

        foreach ($this->accounts->findAll() as $account) {
            if (!$account instanceof Account || (float)($account->getDebt() ?? 0) < $min) {
                continue;
            }

            if ($audience === self::AUDIENCE_UNREACHABLE && $this->reachableInTelegram($account)) {
                $plan['skippedTelegram']++;
                continue;
            }

            $phone = $this->phoneFor($account);
            if ($phone === null) {
                $plan['noPhone']++;
                continue;
            }

            $text = DebtNotifySmsCommand::text($account);
            if ($text === null) {
                $plan['tooLong'][] = $account;
                continue;
            }

            $plan['recipients'][] = ['account' => $account, 'phone' => $phone, 'text' => $text];
        }

        // Largest debt first: it is the order anybody reading the list wants it in.
        usort($plan['recipients'], static fn (array $a, array $b) =>
            (float)$b['account']->getDebt() <=> (float)$a['account']->getDebt());

        return $plan;
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
     * @param array{recipients: list<array{account: Account, phone: string, text: string}>} $plan
     *
     * @return array{sent: int, failed: int, parts: int, outOfMoney: bool, logs: list<SmsLog>}
     */
    public function send(array $plan, string $sentBy, bool $dryRun = false): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'parts' => 0, 'outOfMoney' => false, 'logs' => []];

        foreach ($plan['recipients'] as $r) {
            if ($result['outOfMoney']) {
                $result['logs'][] = $this->sms->refuse(
                    $r['phone'], $r['text'], SmsLog::PURPOSE_DEBT, $r['account'], $sentBy, self::OUT_OF_MONEY,
                );
                $result['failed']++;
                continue;
            }

            $log = $this->sms->send($r['phone'], $r['text'], SmsLog::PURPOSE_DEBT, $r['account'], null, $sentBy, $dryRun);

            $result['logs'][] = $log;
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
        }

        return $result;
    }

    /** Why the households after the point the money ran out were not written to. */
    public const OUT_OF_MONEY = 'на балансі TurboSMS закінчились кошти — розсилку зупинено';

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
     */
    private function phoneFor(Account $account): ?string
    {
        foreach ($account->getUsers() as $user) {
            if ($user->getPhoneNumber()) {
                return $user->getPhoneNumber();
            }
        }

        foreach ($this->expected->forAccount($account) as $expected) {
            if ($expected->getPhone() !== '') {
                return $expected->getPhone();
            }
        }

        return null;
    }
}
