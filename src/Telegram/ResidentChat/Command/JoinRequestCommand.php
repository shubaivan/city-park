<?php

namespace App\Telegram\ResidentChat\Command;

use App\Service\ResidentChatService;
use SergiX44\Nutgram\Nutgram;

/**
 * Somebody tapped the residents' chat link. Telegram holds them at the door and asks us.
 *
 * The whole gate is this one update: the link may be forwarded, posted in the old Viber
 * group or printed on the entrance door without weakening anything, because what gets
 * checked is the user_id actually knocking, not who holds the link.
 */
class JoinRequestCommand
{
    public function __construct(private ResidentChatService $residentChat) {}

    public function __invoke(Nutgram $bot): void
    {
        $request = $bot->chatJoinRequest();

        if (!$request) {
            return;
        }

        $this->residentChat->handleJoinRequest($bot, $request);
    }
}
