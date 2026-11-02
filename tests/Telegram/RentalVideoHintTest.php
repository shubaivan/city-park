<?php

namespace App\Tests\Telegram;

use App\Service\RentalVideoHintText;
use App\Telegram\Rental\Command\RentalVideo;
use App\Telegram\Rental\Command\RentalVideoHint;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

/**
 * A video sent to the bot gets an answer. Silence reads as the bot being down, and that is
 * what a resident with no photos, only a video, ran into at the description step.
 */
class RentalVideoHintTest extends TestCase
{
    private const USER_ID = 485598262;

    private const VIDEO = ['file_id' => 'v', 'file_unique_id' => 'u', 'width' => 1, 'height' => 1, 'duration' => 5];
    private const NOTE = ['file_id' => 'n', 'file_unique_id' => 'u', 'length' => 1, 'duration' => 5];

    /** @return array<string, array{string, array<string, mixed>, ?string}> */
    public static function messages(): array
    {
        return [
            'a video' => ['video', self::VIDEO, RentalVideoHintText::OUTSIDE],
            'a video note' => ['video_note', self::NOTE, RentalVideo::NOTE_REFUSED],
            'a video sent as a file' => ['document', ['file_id' => 'd', 'file_unique_id' => 'u', 'mime_type' => 'video/mp4'], RentalVideo::FILE_REFUSED],
            'some other file stays unanswered' => ['document', ['file_id' => 'd', 'file_unique_id' => 'u', 'mime_type' => 'application/pdf'], null],
        ];
    }

    /**
     * @dataProvider messages
     * @param array<string, mixed> $payload
     */
    public function testOutsideAConversation(string $field, array $payload, ?string $expected): void
    {
        $texts = [];
        $message = Message::fromArray([
            'message_id' => 10,
            'date' => 1756800000,
            'chat' => ['id' => self::USER_ID, 'type' => 'private'],
            $field => $payload,
        ]);

        $bot = $this->createMock(Nutgram::class);
        $bot->method('isCallbackQuery')->willReturn(false);
        $bot->method('message')->willReturn($message);
        $bot->method('sendMessage')->willReturnCallback(function (string $text) use (&$texts) {
            $texts[] = $text;

            return null;
        });

        (new RentalVideoHint())($bot);

        $this->assertSame($expected === null ? [] : [$expected], $texts);
    }

    /** The message under a button on a video card has a video too; a tap is not sending one. */
    public function testATapOnAVideoCardIsNotAVideoBeingSent(): void
    {
        $bot = $this->createMock(Nutgram::class);
        $bot->method('isCallbackQuery')->willReturn(true);
        $bot->expects($this->never())->method('sendMessage');

        (new RentalVideoHint())($bot);
    }

    public function testAllThreeKindsAreWired(): void
    {
        $config = (string)file_get_contents(__DIR__ . '/../../config/telegram.php');

        foreach (['VIDEO', 'VIDEO_NOTE', 'DOCUMENT'] as $type) {
            $this->assertStringContainsString('MessageType::' . $type . ', \\App\\Telegram\\Rental\\Command\\RentalVideoHint::class', $config);
        }
    }
}
