<?php

namespace App\Telegram\Photo\Command;

use App\Service\PhotoUploadFlow;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class UploadPhotoCommand extends Command
{
    protected string $command = 'photoEvent';
    protected ?string $description = 'Photo upload after pavilion booking';

    public function __construct(
        private PhotoUploadFlow $flow,
        $callable = null,
        ?string $command = null,
    ) {
        parent::__construct($callable, $command);
    }

    /**
     * Global onPhoto entry point. The actual work lives in PhotoUploadFlow because
     * the booking conversation needs the very same flow (it swallows every update,
     * photos included, while it is active).
     */
    public function handle(Nutgram $bot): void
    {
        $this->flow->process($bot);
    }
}
