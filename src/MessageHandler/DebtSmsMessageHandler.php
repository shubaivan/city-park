<?php

namespace App\MessageHandler;

use App\Message\DebtSmsMessage;
use App\Service\DebtSmsDelivery;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * **Never retried.** A failure can follow a message TurboSMS accepted and billed. The `sms_send`
 * transport has max_retries: 0, which is the rule, and what leaves here is unrecoverable as well,
 * so it holds even if the routing changes (DebtSmsNoRetryTest). The job goes to `failed` for a
 * person to look at, and the run is stopped so the button does not stay locked.
 */
#[AsMessageHandler]
final class DebtSmsMessageHandler
{
    public function __construct(private DebtSmsDelivery $delivery) {}

    public function __invoke(DebtSmsMessage $message): void
    {
        try {
            $this->delivery->deliver($message);
        } catch (\Throwable $e) {
            try {
                $this->delivery->stopAfterFailure($message);
            } catch (\Throwable) {
                // The first failure is the one to report, and tomorrow frees the button anyway.
            }

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }
}
