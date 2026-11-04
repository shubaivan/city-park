<?php

namespace App\MessageHandler;

use App\Message\SmsDeliveryCheckMessage;
use App\Service\SmsDeliveryChecker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deliberately not DebtSmsMessageHandler's shape: a failure here leaves as it is, so the
 * `sms_status` transport retries it. Nothing is spent by asking again (DebtSmsNoRetryTest pins both).
 */
#[AsMessageHandler]
final class SmsDeliveryCheckMessageHandler
{
    public function __construct(private SmsDeliveryChecker $checker) {}

    public function __invoke(SmsDeliveryCheckMessage $message): void
    {
        $this->checker->check($message);
    }
}
