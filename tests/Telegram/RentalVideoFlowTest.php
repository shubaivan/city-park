<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\RentalListing;
use App\Entity\TelegramUser;
use App\Repository\RentalListingRepository;
use App\Service\PhotoUploadFlow;
use App\Service\RentalListingService;
use App\Service\RentalPhotoService;
use App\Service\RentalVideoHintText;
use App\Service\TelegramUserService;
use App\Telegram\Rental\Command\RentalMenuCommand;
use App\Telegram\Rental\Command\RentalVideo;
use App\Telegram\Rental\Command\RentalVideoHint;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaVideo;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The owner's side of a video, driven through the real handlers with Telegram replaced.
 *
 * The bot double keeps the real method signatures, so a wrong named argument throws here as
 * it would in production; the handlers catch that and fall back, which is why these tests
 * assert the call that should have happened rather than the absence of an error.
 */
class RentalVideoFlowTest extends TestCase
{
    private const LISTING = 7;
    private const PHOTO = '/uploads/rental-photos/flow-test.jpg';

    private string $publicDir;

    /** @var array<string, list<array<string, mixed>>> method → calls, by parameter name */
    private array $calls = [];

    /** @var array<string, mixed> */
    private array $userData = [];

    /** @var list<string> method names that answer with an error */
    private array $failing = [];

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/rental-video-flow-' . uniqid();
        mkdir($this->publicDir . '/uploads/rental-photos', 0777, true);
        file_put_contents($this->publicDir . self::PHOTO, 'jpeg');
    }

    protected function tearDown(): void
    {
        @unlink($this->publicDir . self::PHOTO);
        @rmdir($this->publicDir . '/uploads/rental-photos');
        @rmdir($this->publicDir . '/uploads');
        @rmdir($this->publicDir);
    }

    private function bot(?array $message = null, ?string $callback = null): Nutgram
    {
        $bot = $this->createMock(Nutgram::class);
        $bot->method('isCallbackQuery')->willReturn($callback !== null);

        if ($message !== null) {
            $bot->method('message')->willReturn(Message::fromArray([
                'message_id' => 10,
                'date' => 1756800000,
                'chat' => ['id' => 1, 'type' => 'private'],
                ...$message,
            ]));
        }

        if ($callback !== null) {
            $bot->method('callbackQuery')->willReturn(\SergiX44\Nutgram\Telegram\Types\Common\Update::fromArray([
                'update_id' => 1,
                'callback_query' => ['id' => '1', 'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'R'], 'chat_instance' => 'x', 'data' => $callback],
            ])->callback_query);
        }

        foreach (['sendMessage', 'sendVideo', 'sendPhoto', 'editMessageMedia', 'editMessageText', 'editMessageReplyMarkup', 'answerCallbackQuery', 'deleteMessage'] as $method) {
            $names = array_map(static fn (\ReflectionParameter $p) => $p->getName(), (new \ReflectionMethod(Nutgram::class, $method))->getParameters());
            $bot->method($method)->willReturnCallback(function (...$values) use ($method, $names) {
                if (in_array($method, $this->failing, true)) {
                    throw new \RuntimeException('Bad Request');
                }
                $this->calls[$method][] = array_combine(array_slice($names, 0, count($values)), $values);

                return null;
            });
        }

        $bot->method('getUserData')->willReturnCallback(fn ($key) => $this->userData[$key] ?? null);
        $bot->method('setUserData')->willReturnCallback(function ($key, $value) {
            $this->userData[$key] = $value;

            return true;
        });

        return $bot;
    }

    private function listing(bool $video = true, array $photos = []): RentalListing
    {
        $account = (new Account())->setAccountNumber('1-1-0-085')->setApartmentNumber('85')->setHouseNumber('1')->setStreet('Козацька');
        $listing = (new RentalListing())->setAccount($account)->setPrice(1000)->setPhotos($photos);
        (new \ReflectionProperty(RentalListing::class, 'id'))->setValue($listing, self::LISTING);

        return $video ? $listing->setVideo('FILE', 'unique', 'thumb', 10, 5, 'video/mp4') : $listing;
    }

    private function users(bool $owner = true): TelegramUserService
    {
        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn(new TelegramUser());
        $users->method('resolveAccount')->willReturn($owner ? new Account() : null);

        return $users;
    }

    private function rentals(RentalListing $listing): RentalListingService
    {
        $service = $this->createMock(RentalListingService::class);
        $service->method('findLive')->willReturn($listing);
        $service->method('isOwnObject')->willReturn(true);
        $service->method('describe')->willReturn('CARD');
        $service->method('contactHint')->willReturn('HINT');

        return $service;
    }

    private function conversation(RentalListingService $service, Nutgram $bot): RentalVideo
    {
        $conversation = new RentalVideo($this->users(), $service, $this->createMock(PhotoUploadFlow::class));
        $conversation->listingId = self::LISTING;
        (new \ReflectionProperty(Conversation::class, 'bot'))->setValue($conversation, $bot);

        return $conversation;
    }

    /** @param array<string, mixed> $video */
    private function receive(array $video, ?string $album = null): ?array
    {
        $attached = null;
        $service = $this->rentals($this->listing(false));
        $service->method('attachVideo')->willReturnCallback(function (...$args) use (&$attached) {
            $attached = $args;
        });

        $bot = $this->bot(['video' => $video + ['file_id' => 'NEW', 'file_unique_id' => 'U', 'width' => 1, 'height' => 1], ...($album ? ['media_group_id' => $album] : [])]);
        $this->conversation($service, $bot)->receive($bot);

        return $attached;
    }

    private function menu(RentalListing $listing, ?RentalListingService $service = null): RentalMenuCommand
    {
        $repository = $this->createMock(RentalListingRepository::class);
        $repository->method('find')->willReturn($listing);

        return new RentalMenuCommand(
            $this->users(),
            $service ?? $this->rentals($listing),
            $repository,
            new RentalPhotoService($repository, $this->createMock(\Doctrine\ORM\EntityManagerInterface::class), new NullLogger(), $this->publicDir),
            $this->createMock(UrlGeneratorInterface::class),
            new NullLogger(),
        );
    }

    private function lastText(): string
    {
        return (string)(end($this->calls['sendMessage'])['text'] ?? '');
    }

    public function testAVideoWithinTheLimitIsAttachedWithItsFacts(): void
    {
        $attached = $this->receive(['duration' => RentalListing::VIDEO_MAX_SECONDS, 'file_size' => 1000, 'mime_type' => 'video/mp4', 'thumbnail' => ['file_id' => 'T', 'file_unique_id' => 't', 'width' => 1, 'height' => 1]]);

        $this->assertSame('NEW', $attached[1]);
        $this->assertSame('U', $attached[2]);
        $this->assertSame('T', $attached[3]);
        $this->assertSame(1000, $attached[4]);
        $this->assertSame(RentalListing::VIDEO_MAX_SECONDS, $attached[5]);
        $this->assertSame('video/mp4', $attached[6]);
        $this->assertStringContainsString('✅', $this->lastText());
    }

    public function testAVideoOverThreeMinutesIsRefused(): void
    {
        $this->assertNull($this->receive(['duration' => RentalListing::VIDEO_MAX_SECONDS + 1]));
        $this->assertStringContainsString('довше', $this->lastText());
    }

    public function testAVideoWithNoDurationIsRefused(): void
    {
        $this->assertNull($this->receive(['duration' => 0]));
        $this->assertStringContainsString('тривалість', $this->lastText());
    }

    public function testAVideoOverTheSizeCeilingIsRefused(): void
    {
        $this->assertNull($this->receive(['duration' => 60, 'file_size' => RentalListing::VIDEO_MAX_BYTES + 1]));
        $this->assertStringContainsString('завелике', $this->lastText());
    }

    public function testAnUnknownSizeIsNotHeldAgainstIt(): void
    {
        $this->assertNotNull($this->receive(['duration' => 60]));
    }

    public function testNotAVideoLeavesTheListingAlone(): void
    {
        $service = $this->rentals($this->listing(false));
        $service->expects($this->never())->method('attachVideo');

        $bot = $this->bot(['text' => 'hello']);
        $this->conversation($service, $bot)->receive($bot);

        $this->assertStringContainsString('Чекаю', $this->lastText());
    }

    public function testTheRestOfAnAlbumGetsNoHintAfterTheFirstIsTaken(): void
    {
        $this->assertNotNull($this->receive(['duration' => 60], 'ALBUM'));
        $this->assertSame(RentalVideoHintText::ALBUM_ONE_TAKEN, $this->lastText());
        $this->assertStringContainsString('одне відео', $this->lastText());

        $sent = count($this->calls['sendMessage']);
        (new RentalVideoHint())($this->bot(['media_group_id' => 'ALBUM', 'video' => ['file_id' => 'B', 'file_unique_id' => 'B', 'width' => 1, 'height' => 1, 'duration' => 9]]));

        $this->assertCount($sent, $this->calls['sendMessage']);
    }

    public function testAnotherAlbumOutsideAConversationIsAnsweredOnce(): void
    {
        $message = ['media_group_id' => 'OTHER', 'video' => ['file_id' => 'B', 'file_unique_id' => 'B', 'width' => 1, 'height' => 1, 'duration' => 9]];

        (new RentalVideoHint())($this->bot($message));
        (new RentalVideoHint())($this->bot($message));

        $this->assertCount(1, $this->calls['sendMessage']);
        $this->assertSame(RentalVideoHintText::OUTSIDE, $this->lastText());
    }

    public function testTheCardOpensOnTheVideoWithItsFileIdAndKeyboard(): void
    {
        ($this->menu($this->listing(photos: [self::PHOTO])))($this->bot(callback: 'rent:view:' . self::LISTING));

        $this->assertCount(1, $this->calls['sendVideo']);
        $call = $this->calls['sendVideo'][0];
        $this->assertSame('FILE', $call['video']);
        $this->assertStringContainsString('CARD', $call['caption']);
        $this->assertTrue($call['supports_streaming']);
        $this->assertNotNull($call['reply_markup']);
        $this->assertArrayNotHasKey('sendPhoto', $this->calls);
    }

    public function testAVideoThatWillNotSendFallsBackToThePhoto(): void
    {
        $this->failing = ['sendVideo'];
        ($this->menu($this->listing(photos: [self::PHOTO])))($this->bot(callback: 'rent:view:' . self::LISTING));

        $this->assertCount(1, $this->calls['sendPhoto']);
    }

    public function testAVideoThatWillNotSendAndNoPhotosFallsBackToText(): void
    {
        $this->failing = ['sendVideo'];
        ($this->menu($this->listing()))($this->bot(callback: 'rent:view:' . self::LISTING));

        $this->assertArrayNotHasKey('sendPhoto', $this->calls);
        $this->assertStringContainsString('CARD', $this->calls['editMessageText'][0]['text']);
    }

    public function testTheArrowsSwapToThePhotoAndBackToTheVideo(): void
    {
        $listing = $this->listing(photos: [self::PHOTO]);

        ($this->menu($listing))($this->bot(callback: sprintf('rent:pic:%d:1', self::LISTING)));
        $this->assertInstanceOf(InputMediaPhoto::class, $this->calls['editMessageMedia'][0]['media']);

        ($this->menu($listing))($this->bot(callback: sprintf('rent:pic:%d:0', self::LISTING)));
        $media = $this->calls['editMessageMedia'][1]['media'];
        $this->assertInstanceOf(InputMediaVideo::class, $media);
        $this->assertSame('FILE', $media->media);
        $this->assertTrue($media->supports_streaming);
        $this->assertStringContainsString('CARD', $media->caption);
        $this->assertNotNull($this->calls['editMessageMedia'][1]['reply_markup']);
    }

    public function testDeletingAsksFirstAndDeletesNothing(): void
    {
        $listing = $this->listing();
        $service = $this->rentals($listing);
        $service->expects($this->never())->method('removeVideo');

        ($this->menu($listing, $service))($this->bot(callback: 'rent:vdel:' . self::LISTING));

        $this->assertNotNull($this->calls['editMessageReplyMarkup'][0]['reply_markup']);
        $buttons = json_encode($this->calls['editMessageReplyMarkup'][0]['reply_markup']);
        $this->assertStringContainsString('rent:vdelok:' . self::LISTING, $buttons);
    }

    public function testConfirmingDeletesTheVideoAndRedrawsTheCard(): void
    {
        $listing = $this->listing();
        $service = $this->rentals($listing);
        $service->expects($this->once())->method('removeVideo')->with($listing)
            ->willReturnCallback(static function (RentalListing $l): void {
                $l->clearVideo();
            });

        ($this->menu($listing, $service))($this->bot(callback: 'rent:vdelok:' . self::LISTING));

        $this->assertArrayNotHasKey('sendVideo', $this->calls);
        $this->assertStringContainsString('CARD', $this->calls['editMessageText'][0]['text']);
    }

    public function testSomebodyElsesListingIsNotDeleted(): void
    {
        $listing = $this->listing();
        $service = $this->createMock(RentalListingService::class);
        $service->method('isOwnObject')->willReturn(false);
        $service->expects($this->never())->method('removeVideo');
        $service->method('describe')->willReturn('CARD');

        try {
            ($this->menu($listing, $service))($this->bot(callback: 'rent:vdelok:' . self::LISTING));
        } catch (\Throwable) {
            // The menu it falls back to needs more than this double has; nothing was deleted.
        }

        $this->assertTrue($listing->hasVideo());
    }
}
