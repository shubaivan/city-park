<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Webhook;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use \App\Telegram\Start\Command\StartCommand;

Conversation::refreshOnDeserialize();

$bot->setRunningMode(Webhook::class);

/**
 * Everything below this line was written for a one-to-one chat with the bot.
 *
 * As an administrator of the residents' group the bot loses Telegram's privacy mode and
 * receives that group's messages as well — and the handlers cannot tell the difference:
 * onPhoto would file a picture posted in the chat as pavilion evidence and close
 * somebody's photo obligation, onContact would treat a forwarded contact card as the
 * sender confirming their own phone, and a live conversation would swallow group chatter
 * as an answer to its last question.
 *
 * So the private-chat rule lives here, once, instead of in every handler. The chat gate's
 * own updates are about a group by definition and are the only ones let through.
 */
$bot->middleware(function (Nutgram $bot, $next) {
    $update = $bot->update();

    if ($update === null) {
        $next($bot);

        return;
    }

    $gateUpdates = [
        UpdateType::CHAT_JOIN_REQUEST,
        UpdateType::CHAT_MEMBER,
        UpdateType::MY_CHAT_MEMBER,
    ];

    if (in_array($update->getType(), $gateUpdates, true)) {
        $next($bot);

        return;
    }

    $type = $update->getChat()?->type;
    $type = $type instanceof ChatType ? $type->value : $type;

    // No chat at all (inline queries, poll answers) keeps the old behaviour: those
    // never carried a chat to be confused about in the first place.
    if ($type === null || $type === ChatType::PRIVATE->value) {
        $next($bot);
    }
});

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

$bot->onCallbackQueryData(\App\Telegram\Rental\Command\RentalMenuCommand::MENU_CALLBACK, \App\Telegram\Rental\Command\RentalMenuCommand::class);
$bot->onCallbackQueryData('^rent:(?:(?:view|page|photos|contact|phone|extend|remove):\d+|pic:\d+:\d+|noop)$', \App\Telegram\Rental\Command\RentalMenuCommand::class);
$bot->onCallbackQueryData(\App\Telegram\Rental\Command\RentalPublish::START_CALLBACK, \App\Telegram\Rental\Command\RentalPublish::class);
$bot->onCommand('rent', \App\Telegram\Rental\Command\RentalMenuCommand::class);

$bot->onCallbackQueryData(\App\Telegram\Voting\Command\VotingMenuCommand::MENU_CALLBACK, \App\Telegram\Voting\Command\VotingMenuCommand::class);
$bot->onCallbackQueryData('^bvote:\d+:(yes|no)$', \App\Telegram\Voting\Command\VotingMenuCommand::class);
$bot->onCommand('vote', \App\Telegram\Voting\Command\VotingMenuCommand::class);

// The debtors' board: the menu block is rendered by StartCommand, this is the full list.
$bot->onCallbackQueryData(\App\Telegram\Debt\Command\DebtBoardCommand::MENU_CALLBACK, \App\Telegram\Debt\Command\DebtBoardCommand::class);
$bot->onCommand('debts', \App\Telegram\Debt\Command\DebtBoardCommand::class);

// The residents' chat: the gate on the door, and the button that hands out the key.
$bot->onChatJoinRequest(\App\Telegram\ResidentChat\Command\JoinRequestCommand::class);
$bot->onCallbackQueryData(\App\Telegram\ResidentChat\Command\ResidentChatCommand::MENU_CALLBACK, \App\Telegram\ResidentChat\Command\ResidentChatCommand::class);
$bot->onCommand('chat', \App\Telegram\ResidentChat\Command\ResidentChatCommand::class);
