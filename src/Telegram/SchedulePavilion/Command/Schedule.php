<?php

namespace App\Telegram\SchedulePavilion\Command;

use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class Schedule extends Command
{
    protected string $command = 'schedule';
    protected ?string $description = 'Бронювання';

    /** The same screen as «📅 Бронювання альтанки» on the main menu — one menu, not two. */
    public function handle(Nutgram $bot): void
    {
        StartCommand::pavilionMenu($bot);
    }
}
