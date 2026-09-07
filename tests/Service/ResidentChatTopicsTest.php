<?php

namespace App\Tests\Service;

use App\Repository\TelegramUserRepository;
use App\Service\ResidentChatService;
use App\Service\TelegramUserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which forum topic each kind of post goes into.
 *
 * The group got Topics on 07.09.2026, and the failure mode to avoid is not "the post
 * lands in the wrong tab" — it is "the post is not sent at all". Telegram rejects a
 * message whose `message_thread_id` names a topic that does not exist, so anything the
 * env cannot be trusted to hold has to resolve to null, which is Telegram's own word for
 * «General». A «🆕 Нова заявка» in the wrong tab is a nuisance; a «🆕 Нова заявка» that
 * throws is a broken lift nobody hears about.
 */
class ResidentChatTopicsTest extends TestCase
{
    private function service(string $complaints = '', string $debt = ''): ResidentChatService
    {
        return new ResidentChatService(
            $this->createMock(TelegramUserRepository::class),
            $this->createMock(TelegramUserService::class),
            new NullLogger(),
            '-1001234567890',
            'https://t.me/+abc',
            $complaints,
            $debt,
        );
    }

    public function testConfiguredTopicsAreReturnedAsInts(): void
    {
        $service = $this->service('12', '34');

        $this->assertSame(12, $service->topic(ResidentChatService::TOPIC_COMPLAINTS));
        $this->assertSame(34, $service->topic(ResidentChatService::TOPIC_DEBT));
    }

    /** An empty env is the pre-Topics behaviour, not an error: post into General. */
    public function testAnUnsetTopicFallsBackToGeneral(): void
    {
        $service = $this->service();

        $this->assertNull($service->topic(ResidentChatService::TOPIC_COMPLAINTS));
        $this->assertNull($service->topic(ResidentChatService::TOPIC_DEBT));
    }

    /**
     * @dataProvider rubbish
     */
    public function testAnythingThatIsNotAThreadIdIsTreatedAsUnset(string $value): void
    {
        $this->assertNull($this->service($value)->topic(ResidentChatService::TOPIC_COMPLAINTS));
    }

    /** @return array<string, array{string}> */
    public static function rubbish(): array
    {
        return [
            'a comment left in the env' => ['# 12'],
            'the chat id by mistake' => ['-1001234567890'],
            'General spelled out' => ['0'],
            'a link instead of an id' => ['t.me/c/1234/12'],
            'whitespace' => [' 12'],
        ];
    }

    public function testAnUnknownKindNeverGuesses(): void
    {
        $this->assertNull($this->service('12', '34')->topic('announcements'));
    }
}
