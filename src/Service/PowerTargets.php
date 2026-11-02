<?php

namespace App\Service;

/**
 * Every chat the outage schedule goes to.
 *
 * Besides the residents' topic: «Зahidna», the group of провулок Західний, where Иван is
 * the street elder (02.11.2026). Same picture, same reminders, its own queue, no topic.
 * Its queue is read from пров. Західний 6, the address he gave; the lane has few accounts
 * in the company's cabinet, and on 02.11.2026 houses 17, 19 and 21 answered «в чергу не
 * включено» while the rest said 3.1 — so the caption names the lane and the queue, and
 * nobody's house number.
 *
 * The chat id lives in the environment, like RESIDENT_CHAT_ID: the repository is public.
 * Unset means the group gets nothing and nothing else changes.
 */
class PowerTargets
{
    public const ZAKHIDNA = 'zakhidna';

    private const ZAKHIDNA_STREET = 'прв. Західний';
    private const ZAKHIDNA_HOUSES = ['6'];
    private const ZAKHIDNA_PLACE = 'пров. Західний';

    public function __construct(
        private ResidentChatService $residentChat,
        private string $powerZakhidnaChatId = '',
    ) {}

    public function residents(): PowerTarget
    {
        return new PowerTarget(
            PowerTarget::RESIDENTS,
            $this->residentChat->chatId(),
            $this->residentChat->topic(ResidentChatService::TOPIC_POWER),
            PowerQueueResolver::STREET,
            PowerQueueResolver::HOUSES,
        );
    }

    /** @return list<PowerTarget> the chats other than the residents' that are configured */
    public function others(): array
    {
        if ($this->powerZakhidnaChatId === '') {
            return [];
        }

        return [new PowerTarget(
            self::ZAKHIDNA,
            $this->powerZakhidnaChatId,
            null,
            self::ZAKHIDNA_STREET,
            self::ZAKHIDNA_HOUSES,
            self::ZAKHIDNA_PLACE,
        )];
    }
}
