<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\RequestSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * The bot only ever learns a person's chat_id from a one-to-one chat with them.
 *
 * chat_id is the address of every outgoing notice — debts, photo reminders and blocks,
 * vote notices, the rental phone relay — and initUser() overwrites it from whatever chat
 * an update arrived in. Once the bot became an administrator of «ЖК City Park» it began
 * receiving that group's updates in the same shape, so without this rule a resident who
 * simply wrote in the chat would have their personal mail redirected to it: the next
 * debt notice would name the amount in front of 150 neighbours.
 *
 * It is not hypothetical. On 02.09.2026, before a single message had been posted, the
 * service message "Ivan added the bot" alone re-pointed the owner's own chat_id at the
 * group (telegram_user #1, restored by hand).
 */
class PrivateChatGuardTest extends TestCase
{
    /** @return array<string, mixed>|null */
    private function sender(array $update): ?array
    {
        $method = new \ReflectionMethod(RequestSubscriber::class, 'privateChatSender');

        return $method->invoke(null, $update);
    }

    public function testPrivateMessageIsTheUsersOwnChat(): void
    {
        $from = $this->sender([
            'message' => [
                'from' => ['id' => 471925876, 'first_name' => 'Ivan'],
                'chat' => ['id' => 471925876, 'type' => 'private'],
            ],
        ]);

        $this->assertSame(471925876, $from['chat_id'] ?? null);
    }

    public function testGroupMessageIsIgnoredEntirely(): void
    {
        $from = $this->sender([
            'message' => [
                'from' => ['id' => 471925876, 'first_name' => 'Ivan'],
                'chat' => ['id' => -1002345678901, 'type' => 'supergroup', 'title' => 'ЖК City Park'],
                'text' => 'у когось є контакт електрика?',
            ],
        ]);

        $this->assertNull($from, 'a group message must not touch the user record at all');
    }

    public function testServiceMessageInAGroupIsIgnored(): void
    {
        $from = $this->sender([
            'message' => [
                'from' => ['id' => 471925876, 'first_name' => 'Ivan'],
                'chat' => ['id' => -5575776314, 'type' => 'group', 'title' => 'ЖК City Park'],
                'new_chat_members' => [['id' => 123, 'is_bot' => true, 'username' => 'city_park']],
            ],
        ]);

        $this->assertNull($from, 'adding the bot to a group is exactly how this broke once');
    }

    public function testCallbackFromAGroupIsIgnored(): void
    {
        $from = $this->sender([
            'callback_query' => [
                'from' => ['id' => 471925876, 'first_name' => 'Ivan'],
                'data' => 'main-menu',
                'message' => ['chat' => ['id' => -1002345678901, 'type' => 'supergroup']],
            ],
        ]);

        $this->assertNull($from);
    }

    public function testCallbackFromAPrivateChatStillWorks(): void
    {
        $from = $this->sender([
            'callback_query' => [
                'from' => ['id' => 471925876, 'first_name' => 'Ivan'],
                'data' => 'main-menu',
                'message' => ['chat' => ['id' => 471925876, 'type' => 'private']],
            ],
        ]);

        $this->assertSame(471925876, $from['chat_id'] ?? null);
    }
}
