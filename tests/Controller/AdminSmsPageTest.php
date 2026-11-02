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
    private function render(array $entries, ?array $preview = null, string $role = 'ROLE_ADMIN', ?float $balance = 412.5): string
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
     * On arrival: debtors, who gets it, what it costs, what is on the account — and the one
     * button, with the price beside it. It leads to a confirmation, not to a send.
     */
    public function testTheSummaryAndTheButtonAreThereOnArrival(): void
    {
        $html = $this->render([], $this->preview(3, balance: 100.0));

        $this->assertMatchesRegularExpression('/Боржників понад 5 000 грн<\/td><td[^>]*><b>8<\/b>/u', $html);
        $this->assertMatchesRegularExpression('/Отримають SMS<\/td><td[^>]*><b>3<\/b>/u', $html);
        $this->assertMatchesRegularExpression('/Частин на одне повідомлення<\/td><td[^>]*><b>1<\/b>/u', $html);
        $this->assertStringContainsString('<b>3.87 грн</b>', $html);
        $this->assertStringContainsString('<b>100.00 грн</b>', $html);
        $this->assertStringContainsString('буд. 27, кв. 63', $html);
        $this->assertMatchesRegularExpression('#<a href="/admin/sms/debt/confirm"[^>]*>📨 Відправити СМС</a>#u', $html);
        $this->assertStringNotContainsString('<form method="post"', $html);
    }

    /**
     * Blocked, the button is still there, greyed, with the reason. Hiding it is what made
     * Иван and Людмила report that the page has no button at all.
     */
    public function testABlockedRunShowsTheButtonGreyedWithTheReason(): void
    {
        $preview = $this->preview(8, balance: 9.13);
        $preview['blocked'] = 'бракує 1.19 грн';

        $html = $this->render([], $preview);

        $this->assertMatchesRegularExpression('#<button type="button"[^>]*disabled>📨 Відправити СМС</button>#u', $html);
        $this->assertStringNotContainsString('/admin/sms/debt', $html);
        $this->assertStringContainsString('бракує 1.19 грн', $html);
        $this->assertStringContainsString('10.32 грн', $html);
    }

    /** Why a household is not in the count is on the card, not a gap in the arithmetic. */
    public function testItNamesWhoIsLeftOut(): void
    {
        $preview = $this->preview(1, balance: 100.0);
        $preview['invalidPhone'] = [['phone' => '48796496316']];
        $preview['alreadySent'] = [['phone' => '380671111111'], ['phone' => '380672222222']];
        $preview['samePhone'] = [['phone' => '380673333333']];

        $html = $this->render([], $preview);

        $this->assertMatchesRegularExpression('/Без номера \(SMS не піде\)<\/td><td[^>]*>5</u', $html);
        $this->assertMatchesRegularExpression('/Номер не український \(SMS не піде\)<\/td><td[^>]*>1</u', $html);
        $this->assertMatchesRegularExpression('/Вже надіслано сьогодні або результат невідомий<\/td><td[^>]*>2</u', $html);
        $this->assertMatchesRegularExpression('/Той самий номер, що в іншої адреси<\/td><td[^>]*>1</u', $html);
    }

    /** The confirmation: who, how much, the real text, and a form bound to exactly this list. */
    public function testTheConfirmationShowsRecipientsAndCostAndCarriesTheFingerprint(): void
    {
        $this->render([]);
        $preview = $this->preview(3, balance: 100.0);

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

    /** Сергій reads the journal and never sees the button. */
    public function testTheComplaintsRoleDoesNotSeeTheButton(): void
    {
        $html = $this->render([], null, 'ROLE_COMPLAINTS');

        $this->assertStringNotContainsString('debt-sms', $html);
    }

    private function preview(int $n, ?float $balance): array
    {
        $recipients = [];
        for ($i = 0; $i < $n; $i++) {
            $account = (new \App\Entity\Account())->setDebt('5430.00')->setHouseNumber('27')->setApartmentNumber('63')->setAccountNumber('520063');
            $recipients[] = ['account' => $account, 'phone' => '380671234567', 'text' => 'x'];
        }

        return [
            'recipients' => $recipients,
            'tooLong' => [],
            'noPhone' => 5,
            'skippedTelegram' => 0,
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
        ];
    }
}
