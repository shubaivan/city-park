<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\SmsLog;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;
use App\Service\DebtSmsCampaign;
use App\Service\SmsSender;
use PHPUnit\Framework\TestCase;

/**
 * The panel's debt SMS button: what happens when TurboSMS runs out of money half-way.
 */
class DebtSmsCampaignTest extends TestCase
{
    /**
     * The first message goes, the second is refused and the balance is now empty: the
     * third must not be sent at all, only journalled with the reason.
     */
    public function testARunStopsWhenTheMoneyRunsOut(): void
    {
        $sms = $this->createMock(SmsSender::class);

        $sms->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            (new SmsLog('380671111111', 't', SmsLog::PURPOSE_DEBT))->markSent('a'),
            (new SmsLog('380672222222', 't', SmsLog::PURPOSE_DEBT))->markFailed('Not enough money (306)'),
        );
        $sms->method('balance')->willReturn(0.4);
        $sms->expects($this->once())->method('refuse')
            ->with('380673333333', 't', SmsLog::PURPOSE_DEBT, $this->anything(), 'панель: luda_boss', DebtSmsCampaign::OUT_OF_MONEY)
            ->willReturn((new SmsLog('380673333333', 't', SmsLog::PURPOSE_DEBT))->markFailed(DebtSmsCampaign::OUT_OF_MONEY));

        $campaign = new DebtSmsCampaign(
            $this->createMock(AccountRepository::class),
            $this->createMock(ExpectedResidentRepository::class),
            $sms,
        );

        $run = $campaign->send(['recipients' => [
            ['account' => new Account(), 'phone' => '380671111111', 'text' => 't'],
            ['account' => new Account(), 'phone' => '380672222222', 'text' => 't'],
            ['account' => new Account(), 'phone' => '380673333333', 'text' => 't'],
        ]], 'панель: luda_boss');

        $this->assertSame(1, $run['sent']);
        $this->assertSame(2, $run['failed']);
        $this->assertTrue($run['outOfMoney']);
        $this->assertCount(3, $run['logs'], 'every household gets a journal row, sent or not');
    }

    /** A wrong number is not an empty balance: the run carries on to the next household. */
    public function testOneBadNumberDoesNotStopTheRun(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            (new SmsLog('380671111111', 't', SmsLog::PURPOSE_DEBT))->markFailed('номер не схожий на телефон'),
            (new SmsLog('380672222222', 't', SmsLog::PURPOSE_DEBT))->markSent('b'),
        );
        $sms->method('balance')->willReturn(400.0);
        $sms->expects($this->never())->method('refuse');

        $campaign = new DebtSmsCampaign(
            $this->createMock(AccountRepository::class),
            $this->createMock(ExpectedResidentRepository::class),
            $sms,
        );

        $run = $campaign->send(['recipients' => [
            ['account' => new Account(), 'phone' => '1', 'text' => 't'],
            ['account' => new Account(), 'phone' => '380672222222', 'text' => 't'],
        ]], 'x');

        $this->assertSame(1, $run['sent']);
        $this->assertFalse($run['outOfMoney']);
    }
}
