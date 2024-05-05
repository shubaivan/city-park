<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\RunningMode\Webhook;
use \App\Telegram\Start\Command\StartCommand;

Conversation::refreshOnDeserialize();

$bot->setRunningMode(Webhook::class);

$bot->registerCommand(StartCommand::class);
$bot->registerCommand(\App\Telegram\ApprovePhone\Command\ApprovePhoneCommand::class);
$bot->registerCommand(\App\Telegram\SchedulePavilion\Command\Schedule::class);

$bot->onContact(\App\Telegram\ApprovePhone\Command\EventApprovePhoneCommand::class);

$bot->onLocation(\App\Telegram\Location\Command\LocationCommand::class);
$bot->onCallbackQueryData('type:route', \App\Telegram\Location\Command\RouteCommand::class);

$bot->onCallbackQueryData('schedule-pavilion', \App\Telegram\SchedulePavilion\Command\SchedulePavilion::class);
$bot->onCommand('обрати павільйон', \App\Telegram\SchedulePavilion\Command\SchedulePavilion::class);
$bot->onCallbackQueryData('own-schedule', \App\Telegram\SchedulePavilion\Command\OwnSchedule::class);
