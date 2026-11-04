<?php

namespace App\Tests\Service;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];
    public ?\Throwable $failWith = null;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->messages[] = $message;

        return new Envelope($message);
    }
}
