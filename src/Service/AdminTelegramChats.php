<?php

namespace App\Service;

/**
 * Which Telegram chat belongs to which panel login.
 *
 * The panel's logins are configured in security.yaml and know nothing of Telegram, so the
 * link is written down in env: `ADMIN_TELEGRAM_CHATS=login:chat_id,login:chat_id`.
 * A login that is not listed simply has no chat.
 */
class AdminTelegramChats
{
    public function __construct(private string $adminTelegramChats = '') {}

    public function chatFor(string $login): ?int
    {
        foreach (explode(',', $this->adminTelegramChats) as $pair) {
            [$name, $chat] = array_pad(explode(':', trim($pair), 2), 2, '');

            if ($name === $login && preg_match('/^-?\d+$/', $chat) === 1) {
                return (int)$chat;
            }
        }

        return null;
    }
}
