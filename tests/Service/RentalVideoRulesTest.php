<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\RentalListing;
use App\Repository\RentalListingRepository;
use App\Service\AdminTelegramChats;
use App\Service\DeepLink;
use App\Service\OwnerGroupService;
use App\Service\RentalListingService;
use App\Service\RentalPhotoService;
use App\Service\ResidentChatService;
use App\Service\TelegramFileProxy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Media\File;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A listing's one video: where it sits in the carousel, what goes with it, what the chat
 * post says, and how the panel gets at the file without showing the browser the bot token.
 */
class RentalVideoRulesTest extends TestCase
{
    private const SECRET_URL = 'https://api.telegram.org/file/bot123:SECRET/videos/x.mp4';

    private function listing(?int $size = 1000, array $photos = []): RentalListing
    {
        return (new RentalListing())
            ->setPhotos($photos)
            ->setVideo('file', 'unique', 'thumb', $size, 30, 'video/mp4');
    }

    public function testTheVideoIsTheFirstSlideAndThePhotosFollow(): void
    {
        $listing = $this->listing(photos: ['/a.jpg', '/b.jpg']);

        $this->assertSame(3, $listing->slideCount());
        $this->assertTrue($listing->isVideoSlide(0));
        $this->assertFalse($listing->isVideoSlide(1));
        $this->assertSame(0, $listing->photoIndexOfSlide(1));
        $this->assertSame(1, $listing->photoIndexOfSlide(2));
    }

    public function testWithoutAVideoTheSlidesAreThePhotos(): void
    {
        $listing = (new RentalListing())->setPhotos(['/a.jpg']);

        $this->assertSame(1, $listing->slideCount());
        $this->assertFalse($listing->isVideoSlide(0));
        $this->assertSame(0, $listing->photoIndexOfSlide(0));
    }

    public function testAVideoAloneIsAWholeListing(): void
    {
        $listing = $this->listing();

        $this->assertSame(1, $listing->slideCount());
        $this->assertFalse($listing->hasPhotos());
        $this->assertTrue($listing->isVideoSlide(0));
    }

    public function testOnlyAKnownSmallVideoPlaysInThePanel(): void
    {
        $this->assertTrue($this->listing(RentalListing::VIDEO_ADMIN_PLAYER_MAX_BYTES)->fitsAdminPlayer());
        $this->assertFalse($this->listing(RentalListing::VIDEO_ADMIN_PLAYER_MAX_BYTES + 1)->fitsAdminPlayer());
        $this->assertFalse($this->listing(null)->fitsAdminPlayer(), 'an unknown size is not assumed small');
        $this->assertFalse((new RentalListing())->fitsAdminPlayer());
    }

    /** Closing a listing for good takes the video too; an admin take-down never calls purge. */
    public function testPurgeTakesTheVideoWithThePhotos(): void
    {
        $listing = $this->listing();

        (new RentalPhotoService(
            $this->createMock(RentalListingRepository::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            sys_get_temp_dir(),
        ))->purge($listing);

        $this->assertFalse($listing->hasVideo());
        $this->assertNull($listing->getVideoSize());
        $this->assertNull($listing->getVideoThumbFileId());
    }

    public function testAddingAndRemovingAVideoRewritesTheChatPost(): void
    {
        $sent = [];
        $bot = $this->createMock(Nutgram::class);
        $bot->method('editMessageText')->willReturnCallback(function (string $text) use (&$sent) {
            $sent[] = $text;

            return null;
        });

        $service = $this->service($bot, chatConfigured: true);
        $listing = (new RentalListing())->setAccount($this->account())->setPrice(1000)->setChatMessageId(77);

        $service->attachVideo($listing, 'file', 'unique', null, 10, 5, 'video/mp4');
        $service->removeVideo($listing);

        $this->assertCount(2, $sent);
        $this->assertStringContainsString('🎬', $sent[0]);
        $this->assertStringNotContainsString('🎬', $sent[1]);
    }

    public function testAPostThatCannotBeEditedStopsNothing(): void
    {
        $bot = $this->createMock(Nutgram::class);
        $bot->method('editMessageText')->willThrowException(new \RuntimeException('message to edit not found'));

        $listing = (new RentalListing())->setAccount($this->account())->setPrice(1000)->setChatMessageId(77);

        $this->service($bot, chatConfigured: true)->attachVideo($listing, 'file', 'unique', null, 10, 5, 'video/mp4');

        $this->assertTrue($listing->hasVideo());
    }

    public function testNoStandingPostMeansNoEdit(): void
    {
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('editMessageText');

        $listing = (new RentalListing())->setAccount($this->account())->setPrice(1000);

        $this->service($bot, chatConfigured: true)->attachVideo($listing, 'file', 'unique', null, 10, 5, 'video/mp4');
    }

    public function testAdminChatsAreReadFromTheLinkList(): void
    {
        $chats = new AdminTelegramChats('main_admin:471, alina:-100200');

        $this->assertSame(471, $chats->chatFor('main_admin'));
        $this->assertSame(-100200, $chats->chatFor('alina'));
        $this->assertNull($chats->chatFor('serhii'));
        $this->assertNull((new AdminTelegramChats(''))->chatFor('main_admin'));
        $this->assertNull((new AdminTelegramChats('main_admin:abc'))->chatFor('main_admin'));
    }

    public function testTheProxyPassesTheRangeOnAndThePartialAnswerBack(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options['normalized_headers']['range'][0] ?? null;

            return new MockResponse('bytes', [
                'http_code' => 206,
                'response_headers' => [
                    'Content-Length: 5',
                    'Content-Range: bytes 0-4/100',
                    'Accept-Ranges: bytes',
                    'X-Upstream: ' . self::SECRET_URL,
                ],
            ]);
        });

        $response = $this->proxy($client)->stream('file', 'video/mp4', 'bytes=0-4');

        $this->assertSame('Range: bytes=0-4', $seen);
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 0-4/100', $response->headers->get('Content-Range'));
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $this->body($response));
        $this->assertStringNotContainsString('SECRET', (string)$response->headers);
    }

    public function testAMalformedRangeIsNotForwarded(): void
    {
        $seen = 'unset';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options['normalized_headers']['range'][0] ?? null;

            return new MockResponse('x');
        });

        $this->proxy($client)->stream('file', 'video/mp4', "bytes=0-4\r\nEvil: 1");

        $this->assertNull($seen);
    }

    public function testAFailureShowsTheBrowserNothingOfTheUrl(): void
    {
        $bot = $this->createMock(Nutgram::class);
        $bot->method('getFile')->willThrowException(new \RuntimeException('failed for ' . self::SECRET_URL));

        $response = (new TelegramFileProxy($bot, new MockHttpClient(), new NullLogger()))->stream('file', 'video/mp4');

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function testAnErrorFromTelegramIsABadGatewayToo(): void
    {
        $response = $this->proxy(new MockHttpClient(new MockResponse('nope', ['http_code' => 404])))->stream('file', 'video/mp4');

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    private function proxy(MockHttpClient $client): TelegramFileProxy
    {
        $file = (new \ReflectionClass(File::class))->newInstanceWithoutConstructor();

        $bot = $this->createMock(Nutgram::class);
        $bot->method('getFile')->willReturn($file);
        $bot->method('downloadUrl')->willReturn(self::SECRET_URL);

        return new TelegramFileProxy($bot, $client, new NullLogger());
    }

    private function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string)ob_get_clean();
    }

    private function account(): Account
    {
        return (new Account())->setAccountNumber('1-1-0-085')->setApartmentNumber('85')->setHouseNumber('1')->setStreet('Козацька');
    }

    private function service(Nutgram $bot, bool $chatConfigured): RentalListingService
    {
        $chat = $this->createMock(ResidentChatService::class);
        $chat->method('isConfigured')->willReturn($chatConfigured);
        $chat->method('chatId')->willReturn('-100');

        return new RentalListingService(
            $this->createMock(RentalListingRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $bot,
            new NullLogger(),
            $this->createMock(RentalPhotoService::class),
            $chat,
            $this->createMock(DeepLink::class),
            $this->createMock(OwnerGroupService::class),
        );
    }
}
