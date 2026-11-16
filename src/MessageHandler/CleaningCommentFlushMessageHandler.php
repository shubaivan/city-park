<?php

namespace App\MessageHandler;

use App\Message\CleaningCommentFlushMessage;
use App\Service\CleaningCommentRelay;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CleaningCommentFlushMessageHandler
{
    public function __construct(private CleaningCommentRelay $relay) {}

    public function __invoke(CleaningCommentFlushMessage $message): void
    {
        $this->relay->flush();
    }
}
