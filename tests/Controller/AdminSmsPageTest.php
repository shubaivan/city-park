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
    private function render(array $entries): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']),
        );

        return self::getContainer()->get(Environment::class)->render('admin/sms.html.twig', [
            'entries' => $entries,
            'month' => ['sent' => 1, 'failed' => 1, 'parts' => 1],
            'shown' => 500,
            'price' => 1.29,
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
}
