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
     * On arrival: debtors, how many have a number, what it costs, what is on the account,
     * and the button saying how many and for how much.
     */
    public function testTheSummaryAndTheButtonAreThereOnArrival(): void
    {
        $preview = $this->preview(3, balance: 100.0);

        $html = $this->render([], $preview);

        $this->assertMatchesRegularExpression('/Боржників понад 5 000 грн<\/td><td[^>]*><b>8<\/b>/u', $html);
        $this->assertMatchesRegularExpression('/З них відомий номер<\/td><td[^>]*><b>3<\/b>/u', $html);
        $this->assertStringContainsString('<b>3.87 грн</b>', $html);
        $this->assertStringContainsString('<b>100.00 грн</b>', $html);
        $this->assertStringContainsString('Надіслати 3 SMS · 3.87 грн', $html);
        $this->assertStringContainsString('name="expected" value="3"', $html);
        $this->assertStringContainsString('буд. 27, кв. 63', $html);
    }

    /** Not enough money on TurboSMS: say so instead of offering a send that half-fails. */
    public function testALowBalanceHidesTheSendButton(): void
    {
        $html = $this->render([], $this->preview(3, balance: 1.0));

        $this->assertStringNotContainsString('/admin/sms/debt', $html);
        $this->assertStringContainsString('Бракує 2.87 грн', $html);
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
            'cost' => $n * 1.29,
            'balance' => $balance,
            'configured' => true,
            'debtors' => $n + 5,
        ];
    }
}
