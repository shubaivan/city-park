<?php

namespace App\Service;

/**
 * One chat that receives the outage schedule: where it goes and whose queue it shows.
 *
 * The residents' topic is the first of these. `key` is what separates a chat's stored
 * queue and posts from another's, so one day has one post per chat and a building number
 * on one street is never read as the same number on another. `place` names the address in
 * the caption instead of the «буд. 17, 19» list; `topic` null means the chat has no
 * topics and the post goes straight into it. `reminders` is whether the «через 15 хвилин»
 * messages go there as well; the daily post and its updates always do.
 */
final readonly class PowerTarget
{
    public const RESIDENTS = 'residents';

    /** @param list<string> $houses */
    public function __construct(
        public string $key,
        public string $chatId,
        public ?int $topic,
        public string $street,
        public array $houses,
        public ?string $place = null,
        public bool $reminders = true,
    ) {}

    public function isResidents(): bool
    {
        return $this->key === self::RESIDENTS;
    }

    /** How a building of this chat is named in the `power:queue:check` table. */
    public function reportKey(string $house): string
    {
        return $this->isResidents() ? $house : sprintf('%s (%s)', $house, $this->place);
    }
}
