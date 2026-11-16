<?php

namespace App\MessageHandler;

use App\Message\CleaningAlbumFlushMessage;
use App\Service\CleaningReportService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CleaningAlbumFlushMessageHandler
{
    public function __construct(private CleaningReportService $reports) {}

    public function __invoke(CleaningAlbumFlushMessage $message): void
    {
        $this->reports->flushAlbum($message->mediaGroupId);
    }
}
