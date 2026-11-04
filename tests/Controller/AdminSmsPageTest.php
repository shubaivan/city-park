<?php

namespace App\Tests\Controller;

use App\Entity\SmsLog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The SMS journal, rendered: to whom, what, and — for a failure — why it did not go.
 */
class AdminSmsPageTest extends KernelTestCase
{
    private function render(array $entries, ?array $preview = null, string $role = 'ROLE_ADMIN', ?float $balance = 412.5, ?array $run = null): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, [$role]), 'main', [$role]),
        );

        // csrf_token() on the send form needs a session to keep the token in.
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));
        self::getContainer()->get('request_stack')->push($request);

        return self::getContainer()->get(Environment::class)->render('admin/sms.html.twig', [
            'entries' => $entries,
            'month' => ['sent' => 1, 'failed' => 1, 'parts' => 1],
            'shown' => 500,
            'price' => 1.29,
            'preview' => $preview,
            'min_debt' => 5000.0,
            'balance' => $balance,
            'turbosms_id' => '8980641',
            'run' => $run,
        ]);
    }

    public function testItShowsRecipientTextAndWhyAFailureDidNotGo(): void
    {
        $sent = (new SmsLog('380671234567', 'Буд.27 кв.63: борг 5430 грн. Просимо сплатити.', SmsLog::PURPOSE_DEBT))
            ->setPlaceLabel('буд. 27, кв. 63')
            ->setSentBy('debt:notify-sms')
            ->markSent('abc');
        $failed = (new SmsLog('380671234567', 'Інший текст', SmsLog::PURPOSE_TEST))
            ->markFailed('сьогодні вже надсилали на цей номер');

        $html = $this->render([$sent, $failed]);

        $this->assertStringContainsString('380671234567', $html);
        $this->assertStringContainsString('буд. 27, кв. 63', $html);
        $this->assertStringContainsString('борг 5430 грн', $html);
        $this->assertStringContainsString('✅ надіслано', $html);
        $this->assertStringContainsString('сьогодні вже надсилали на цей номер', $html);
        $this->assertStringContainsString('debt:notify-sms', $html);
    }

    /** The flat and what it owed when the message went — a snapshot, not today's debt. */
    public function testItShowsTheAddressAndTheDebtAtTheMomentOfSending(): void
    {
        $account = (new \App\Entity\Account())->setDebt('5430.00');
        $log = (new SmsLog('380671234567', 'Буд.27 кв.63: борг 5430 грн. Просимо сплатити.', SmsLog::PURPOSE_DEBT))
            ->setPlaceLabel('буд. 27, кв. 63')
            ->setRecipient($account, null)
            ->markSent('abc');

        $account->setDebt('0');

        $html = $this->render([$log]);

        $this->assertStringContainsString('буд. 27, кв. 63', $html);
        $this->assertStringContainsString('борг 5 430.00 грн', $html);
        $this->assertStringContainsString('станом на ' . date('d.m'), $html);
    }

    /** An SMS costs the ОСББ money, so the page says how much — per message and for the month. */
    public function testItShowsWhatTheSmsCost(): void
    {
        $sent = (new SmsLog('380671234567', 'Буд.27 кв.63: борг 5430 грн. Просимо сплатити.', SmsLog::PURPOSE_DEBT))
            ->markSent('abc');

        $html = $this->render([$sent]);

        $this->assertStringContainsString('1.29 грн за одну SMS', $html);
        $this->assertStringContainsString('× 1.29 грн', $html);
        $this->assertStringContainsString('· 1.29 грн', $html);
    }

    /**
     * On arrival: every debtor with a checkbox, nothing ticked, and the button leading to a
     * confirmation, not to a send.
     */
    public function testTheListAndTheButtonAreThereOnArrival(): void
    {
        $html = $this->render([], $this->preview(3, balance: 100.0));

        $this->assertMatchesRegularExpression('/Боржників: <b>8<\/b> · можна надіслати: <b>3<\/b>/u', $html);
        $this->assertSame(3, substr_count($html, 'class="debt-sms-pick"'));
        $this->assertMatchesRegularExpression('/name="ids\[\]" value="100" data-parts="1"/', $html);
        $this->assertStringNotContainsString(' checked', $html, 'nobody is written to unless somebody ticks them');
        $this->assertSame(3, substr_count($html, 'class="debt-sms-pick" autocomplete="off"'), 'a reload must not bring old ticks back');
        $this->assertStringContainsString('id="debt-sms-all" autocomplete="off"', $html);
        $this->assertStringContainsString('Вибрати всіх (3)', $html);
        $this->assertStringContainsString('буд. 27, кв. 63', $html);
        $this->assertStringContainsString('data-price="1.29"', $html);
        $this->assertStringContainsString('data-balance="100"', $html);
        $this->assertMatchesRegularExpression('#<form id="debt-sms-form" method="get" action="/admin/sms/debt/confirm"#u', $html);
        $this->assertStringNotContainsString('<form method="post"', $html);
        $this->assertMatchesRegularExpression('#<b id="debt-sms-count">0</b> SMS · <b><span id="debt-sms-cost">0.00</span> грн</b>#u', $html);
        $this->assertMatchesRegularExpression('#<button type="submit" id="debt-sms-send"[^>]*disabled>📨 Відправити СМС</button>#u', $html);
    }

    /** Whoever cannot be written to is on the list, greyed, with why: not a gap in the count. */
    public function testARowThatCannotBeTickedIsDisabledWithTheReason(): void
    {
        $html = $this->render([], $this->preview(1, balance: 100.0));

        $this->assertSame(1, substr_count($html, 'class="debt-sms-pick"'));
        $this->assertSame(5, substr_count($html, '⛔ немає номера'));
        $this->assertSame(5, substr_count($html, '<input type="checkbox" disabled aria-label="Не можна вибрати: немає номера">'));
        $this->assertStringContainsString('не можна: без номера 5', $html);
    }

    /**
     * Unavailable, the button is still there, greyed, with the reason. Hiding it is what made
     * Иван and Людмила report that the page has no button at all.
     */
    public function testAnUnavailableCardShowsTheButtonGreyedWithTheReason(): void
    {
        $preview = $this->preview(8, balance: 9.13);
        $preview['unavailable'] = 'тихі години';

        $html = $this->render([], $preview);

        $this->assertMatchesRegularExpression('#<button type="button" id="debt-sms-send"[^>]*disabled>📨 Відправити СМС</button>#u', $html);
        $this->assertStringNotContainsString('/admin/sms/debt', $html);
        $this->assertStringContainsString('тихі години', $html);
        $this->assertMatchesRegularExpression(
            '#<div id="debt-sms-bar">.*тихі години.*</form>#su',
            $html,
            'the reason stays beside the button, not below a list that is 59 rows long on a phone',
        );
    }

    /**
     * The search: every debtor with the debt beside them, and who cannot be written to says
     * so and cannot be picked. It posts nothing itself: what is picked becomes a checkbox.
     */
    public function testTheSearchListsEveryDebtorWithTheDebtAndWhyNot(): void
    {
        $preview = $this->preview(1, balance: 100.0);
        $preview['searchable'] = [
            $this->searchRow(500, '27', '63', '320.50', '380671234567', 1, null),
            $this->searchRow(501, '19', '7', '150.00', null, 0, 'немає номера'),
        ];

        $html = $this->render([], $preview);

        $this->assertMatchesRegularExpression('/<select id="debt-sms-search"(?![^>]*\bname=)[^>]*>/u', $html);
        $this->assertStringContainsString('усі боржники, 2, з номером 1', $html);
        $this->assertMatchesRegularExpression('/<option value="500"\s+data-place="буд. 27, кв. 63" data-debt="320.50" data-phone="380671234567" data-parts="1"\s+data-house="27" data-unit="63" data-kind="[a-z]+">буд. 27, кв. 63 · 320.50 грн · 380671234567<\/option>/u', $html);
        $this->assertMatchesRegularExpression('/<option value="501" disabled\s+[^>]*>буд. 19, кв. 7 · 150.00 грн · ⛔ немає номера<\/option>/u', $html);
        $this->assertStringNotContainsString(' selected', $html, 'nobody is written to unless somebody picks them');
    }

    /** Сергій reads the journal: neither the search nor the phones in it are his. */
    public function testTheComplaintsRoleDoesNotGetTheSearch(): void
    {
        $html = $this->render([], null, 'ROLE_COMPLAINTS');

        $this->assertStringNotContainsString('debt-sms-search', $html);
        // Not the bare entry name: the shared chunks of `app` carry it in their file names.
        $this->assertStringNotContainsString('/index/js/sms_debt.min.js', $html);
    }

    /** Whoever came from the search is counted on the way to the money. */
    public function testTheConfirmationSaysHowManyCameFromTheSearch(): void
    {
        $this->render([]);
        $preview = $this->preview(2, balance: 100.0);
        $preview['fromSearch'] = 1;

        $html = self::getContainer()->get(Environment::class)->render('admin/sms_confirm.html.twig', [
            'preview' => $preview,
            'price' => 1.29,
            'min_debt' => 5000.0,
        ]);

        $this->assertMatchesRegularExpression('/З них додано з пошуку \(борг до 5 000 грн\)<\/td><td[^>]*><b>1</u', $html);
    }

    /** The confirmation: who, how much, the real text, and a form bound to exactly this list. */
    public function testTheConfirmationShowsRecipientsAndCostAndCarriesTheFingerprint(): void
    {
        $this->render([]);
        $preview = $this->preview(3, balance: 100.0);
        $preview['notChosen'] = 2;
        $preview['invalidPhone'] = [['phone' => '48796496316']];

        $html = self::getContainer()->get(Environment::class)->render('admin/sms_confirm.html.twig', [
            'preview' => $preview,
            'price' => 1.29,
            'min_debt' => 5000.0,
        ]);

        $this->assertStringContainsString('Так, відправити 3 SMS · 3.87 грн', $html);
        $this->assertStringContainsString('name="fingerprint" value="abc123"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('action="/admin/sms/debt"', $html);
        $this->assertStringContainsString('<details class="mb-2 small" open', $html);
        $this->assertSame(3, substr_count($html, '380671234567'));
        $this->assertStringContainsString('b.disabled = true', $html, 'a double tap is stopped in the browser too');
        $this->assertStringContainsString('<input type="hidden" name="ids[]" value="100">', $html);
        $this->assertSame(3, substr_count($html, 'name="ids[]"'));
        $this->assertMatchesRegularExpression('/Не вибрано \(SMS не піде\)<\/td><td[^>]*>2</u', $html);
        $this->assertMatchesRegularExpression('/Номер не український \(SMS не піде\)<\/td><td[^>]*>1</u', $html);
    }

    /** What is left on TurboSMS is on the page for everybody, and «unknown» is never «0». */
    public function testItShowsTheTurboSmsBalance(): void
    {
        $this->assertStringContainsString('Баланс TurboSMS:', $html = $this->render([], null, 'ROLE_COMPLAINTS'));
        $this->assertStringContainsString('412.50 грн', $html);
        $this->assertStringContainsString('≈ 319 SMS', $html);

        $unknown = $this->render([], null, 'ROLE_ADMIN', null);
        $this->assertStringContainsString('невідомий', $unknown);
        $this->assertStringNotContainsString('0.00 грн</b>', $unknown);
    }

    /**
     * How to top it up is on the page, with the ID a terminal asks for — the ОСББ pays
     * without going through Иван — and it opens by itself when the balance is low.
     */
    public function testItSaysHowToTopUp(): void
    {
        $html = $this->render([], null, 'ROLE_ADMIN', 412.5);
        $this->assertStringContainsString('Як поповнити рахунок TurboSMS', $html);
        $this->assertStringContainsString('8980641', $html);
        $this->assertStringContainsString('EasyPay', $html);
        $this->assertStringNotContainsString('<details class="mt-2" open', $html);

        $low = $this->render([], null, 'ROLE_ADMIN', 9.13);
        $this->assertStringContainsString('<details class="mt-2" open', $low);
    }

    /** The run on the page: N of M, what failed, what is left, and why it stopped. The journal has the rest. */
    public function testItShowsTheStatusOfTheRun(): void
    {
        $run = new \App\Entity\SmsRun('панель: alina', 8, new \DateTimeImmutable());
        $status = ['run' => $run, 'total' => 8, 'sent' => 3, 'failed' => 1, 'unknown' => 1, 'sending' => 0, 'queued' => 3, 'stalled' => false];

        $html = $this->render([], null, 'ROLE_COMPLAINTS', 100.0, $status);

        $this->assertStringContainsString('Надіслано 3 з 8', $html);
        $this->assertMatchesRegularExpression('#Не надіслано: <b>1</b>#u', $html);
        $this->assertMatchesRegularExpression('#Невідомо, чи пішло: <b>1</b>#u', $html);
        $this->assertMatchesRegularExpression('#У черзі: <b>3</b>#u', $html);
        $this->assertStringContainsString('http-equiv="refresh"', $html, 'the count moves by itself while it goes');

        $run->stop('на балансі TurboSMS закінчились кошти');
        $stopped = $this->render([], null, 'ROLE_COMPLAINTS', 100.0, $status);
        $this->assertStringContainsString('на балансі TurboSMS закінчились кошти', $stopped);
        $this->assertStringNotContainsString('http-equiv="refresh"', $stopped);

        $this->assertStringNotContainsString('id="sms-run"', $this->render([]));
    }

    /** A queue nobody consumes reads as «надсилається» for ever unless the page says otherwise. */
    public function testAStalledRunSaysSo(): void
    {
        $run = new \App\Entity\SmsRun('a', 8, new \DateTimeImmutable());
        $html = $this->render([], null, 'ROLE_ADMIN', 100.0, ['run' => $run, 'total' => 8, 'sent' => 0, 'failed' => 0, 'unknown' => 0, 'sending' => 0, 'queued' => 8, 'stalled' => true]);

        $this->assertStringContainsString('Черга не рухається', $html);
    }

    /** Сергій reads the journal and never sees the button. */
    public function testTheComplaintsRoleDoesNotSeeTheButton(): void
    {
        $html = $this->render([], null, 'ROLE_COMPLAINTS');

        $this->assertStringNotContainsString('debt-sms', $html);
    }

    private function searchRow(int $id, string $house, string $flat, string $debt, ?string $phone, int $parts, ?string $reason): array
    {
        $account = (new \App\Entity\Account())->setDebt($debt)->setHouseNumber($house)->setApartmentNumber($flat)->setAccountNumber('5200' . $flat);
        (new \ReflectionProperty(\App\Entity\Account::class, 'id'))->setValue($account, $id);

        return ['account' => $account, 'phone' => $phone, 'parts' => $parts, 'reason' => $reason];
    }

    private function preview(int $n, ?float $balance): array
    {
        $recipients = [];
        $rows = [];
        for ($i = 0; $i < $n + 5; $i++) {
            $account = (new \App\Entity\Account())->setDebt('5430.00')->setHouseNumber('27')->setApartmentNumber('63')->setAccountNumber('520063');
            (new \ReflectionProperty(\App\Entity\Account::class, 'id'))->setValue($account, 100 + $i);

            if ($i < $n) {
                $recipients[] = ['account' => $account, 'phone' => '380671234567', 'text' => 'x'];
                $rows[] = ['account' => $account, 'phone' => '380671234567', 'parts' => 1, 'reason' => null];
            } else {
                $rows[] = ['account' => $account, 'phone' => null, 'parts' => 0, 'reason' => 'немає номера'];
            }
        }

        return [
            'recipients' => $recipients,
            'rows' => $rows,
            'tooLong' => [],
            'noPhone' => 5,
            'skippedTelegram' => 0,
            'skippedSummary' => 'без номера 5',
            'invalidPhone' => [],
            'alreadySent' => [],
            'samePhone' => [],
            'parts' => $n,
            'partsPerMessage' => 1,
            'cost' => round($n * 1.29, 2),
            'balance' => $balance,
            'configured' => true,
            'debtors' => $n + 5,
            'fingerprint' => 'abc123',
            'blocked' => null,
            'unavailable' => null,
            'rejected' => [],
            'notChosen' => 0,
        ];
    }
}
