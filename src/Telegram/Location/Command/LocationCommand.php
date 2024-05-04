<?php

namespace App\Telegram\Location\Command;

use App\Telegram\Location\Repository\OfficeRepository;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class LocationCommand extends Command
{
    protected string $command = 'location';
    protected ?string $description = 'Listen location';
    private OfficeRepository $officeRepository;

    public function __construct(OfficeRepository $officeRepository, $callable = null, ?string $command = null)
    {
        parent::__construct($callable, $command);
        $this->officeRepository = $officeRepository;
    }


    public function handle(Nutgram $bot): void
    {
        $location = $bot->message()->location;
        $latitude = $location->latitude;
        $longitude = $location->longitude;
        $offices = $this->officeRepository->findNearest($latitude, $longitude);
        foreach ($offices as $office) {
            $reply = sprintf(
                "*%s*\n*Distance*: _%s_ м",
                $office->getName(),
                number_format($office->getDistance($latitude, $longitude), 2, ',', ' ')
            );

            $bot->sendMessage(
                text: $reply,
                parse_mode: ParseMode::MARKDOWN
            );

            $bot->sendLocation(
                $office->getLatitude(),
                $office->getLongitude()
            );
        }
    }
}