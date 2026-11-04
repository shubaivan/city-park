<?php

namespace App\Tests\Service;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];
    /** @var list<Envelope> delivery checks, kept apart: they are not part of a run */
    public array $checks = [];
    public ?\Throwable $failWith = null;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        if ($message instanceof \App\Message\SmsDeliveryCheckMessage) {
            $envelope = new Envelope($message, $stamps);
            $this->checks[] = $envelope;

            return $envelope;
        }
        $this->messages[] = $message;

        return new Envelope($message);
    }
}
