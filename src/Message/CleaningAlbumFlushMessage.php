<?php

namespace App\Message;

/** Post one album of a cleaning report, a few seconds after its first photo arrived. */
final class CleaningAlbumFlushMessage
{
    public function __construct(public readonly string $mediaGroupId) {}
}
