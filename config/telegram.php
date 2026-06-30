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

$bot->onPhoto(\App\Telegram\Photo\Command\UploadPhotoCommand::class);

$bot->onLocation(\App\Telegram\Location\Command\LocationCommand::class);
$bot->onCallbackQueryData('type:route', \App\Telegram\Location\Command\RouteCommand::class);

$bot->onCallbackQueryData('schedule-pavilion', \App\Telegram\SchedulePavilion\Command\SchedulePavilion::class);
$bot->onCommand('обрати павільйон', \App\Telegram\SchedulePavilion\Command\SchedulePavilion::class);
$bot->onCallbackQueryData('own-schedule', \App\Telegram\SchedulePavilion\Command\OwnSchedule::class);
$bot->onCallbackQueryData('booking-history', \App\Telegram\SchedulePavilion\Command\BookingHistory::class);
$bot->onCallbackQueryData('^bh:week:\d{4}-W\d{2}$', \App\Telegram\SchedulePavilion\Command\BookingHistory::class);
$bot->onCallbackQueryData('^bh:photo:\d+$', \App\Telegram\SchedulePavilion\Command\BookingHistory::class);
$bot->onCommand('history', \App\Telegram\SchedulePavilion\Command\BookingHistory::class);

$bot->onCallbackQueryData(\App\Telegram\Start\Command\StartCommand::MAIN_MENU_CALLBACK, \App\Telegram\Start\Command\StartCommand::class);

$bot->onCallbackQueryData('photo-upload-info', \App\Telegram\Photo\Command\PhotoUploadInfo::class);
$bot->onCommand('photo', \App\Telegram\Photo\Command\PhotoUploadInfo::class);

$bot->onCallbackQueryData('info-menu', \App\Telegram\Info\Command\InfoCommand::class);
$bot->onCallbackQueryData('^info-topic:.+$', \App\Telegram\Info\Command\InfoCommand::class);
$bot->onCommand('info', \App\Telegram\Info\Command\InfoCommand::class);

$bot->onCallbackQueryData(\App\Telegram\Voting\Command\VotingMenuCommand::MENU_CALLBACK, \App\Telegram\Voting\Command\VotingMenuCommand::class);
$bot->onCallbackQueryData('^bvote:\d+:(yes|no)$', \App\Telegram\Voting\Command\VotingMenuCommand::class);
$bot->onCommand('vote', \App\Telegram\Voting\Command\VotingMenuCommand::class);
