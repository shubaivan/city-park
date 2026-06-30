<?php

namespace App\MessageHandler;

use App\Message\VoteBroadcastMessage;
use App\Service\BlockVoteService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class VoteBroadcastMessageHandler
{
    public function __construct(private BlockVoteService $voteService) {}

    public function __invoke(VoteBroadcastMessage $message): void
    {
        $this->voteService->deliverOpenedNotice($message->campaignId, $message->recipientAccountId);
    }
}
