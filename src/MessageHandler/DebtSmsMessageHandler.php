<?php

namespace App\MessageHandler;

use App\Message\DebtSmsMessage;
use App\Service\DebtSmsDelivery;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * **Never retried.** The `async` transport retries three times, which is right for a Telegram
 * notice and wrong here: a failure can follow a message TurboSMS accepted and billed. Whatever
 * goes wrong leaves as unrecoverable, so the job goes to the `failed` transport for a person to
 * look at and is not delivered again (DebtSmsNoRetryTest). The transport stays `async`, the one
 * city-park-messenger.service consumes, so no server configuration changes.
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
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }
}
