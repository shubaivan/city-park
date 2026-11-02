<?php

namespace App\Tests\Controller;

use App\Entity\RentalListing;
use App\Repository\RentalListingRepository;
use App\Service\AdminTelegramChats;
use App\Service\TelegramFileProxy;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watching a video from /admin/rentals: the accountant's, nobody else's, in the page up to
 * 20 MB and through the bot above it.
 */
class AdminRentalVideoTest extends WebTestCase
{
    private function loginAs(string $login): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $client->loginUser($provider->loadUserByIdentifier($login));

        return $client;
    }

    private function repositoryWith(?RentalListing $listing): void
    {
        $repository = $this->createMock(RentalListingRepository::class);
        $repository->method('find')->willReturn($listing);
        static::getContainer()->set(RentalListingRepository::class, $repository);
    }

    private function listing(int $size): RentalListing
    {
        $listing = (new RentalListing())->setVideo('file', 'unique', 'thumb', $size, 65, 'video/mp4');
        (new \ReflectionProperty(RentalListing::class, 'id'))->setValue($listing, 7);

        return $listing;
    }

    public function testASmallVideoStreamsThroughTheProxy(): void
    {
        $client = $this->loginAs('alina');
        $this->repositoryWith($this->listing(1000));

        $proxy = $this->createMock(TelegramFileProxy::class);
        $proxy->expects($this->once())->method('stream')->with('file', 'video/mp4', 'bytes=0-1')->willReturn(new Response('ok'));
        static::getContainer()->set(TelegramFileProxy::class, $proxy);

        $client->request('GET', '/admin/rentals/7/video', server: ['HTTP_RANGE' => 'bytes=0-1']);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testALargeVideoIsNotAttempted(): void
    {
        $client = $this->loginAs('alina');
        $this->repositoryWith($this->listing(RentalListing::VIDEO_ADMIN_PLAYER_MAX_BYTES + 1));

        $proxy = $this->createMock(TelegramFileProxy::class);
        $proxy->expects($this->never())->method('stream');
        static::getContainer()->set(TelegramFileProxy::class, $proxy);

        $client->request('GET', '/admin/rentals/7/video');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return [
            'the video' => ['GET', '/admin/rentals/7/video'],
            'the thumbnail' => ['GET', '/admin/rentals/7/video/thumb'],
            'the send button' => ['POST', '/admin/rentals/7/video/send'],
        ];
    }

    /** @dataProvider routes */
    public function testTheComplaintsRoleIsRefused(string $method, string $url): void
    {
        $client = $this->loginAs('serhii');
        $client->request($method, $url);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    /** @dataProvider routes */
    public function testNobodySignedInIsSentToLogin(string $method, string $url): void
    {
        $client = static::createClient();
        $client->request($method, $url);

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('/login', (string)$client->getResponse()->headers->get('Location'));
    }

    public function testAnAdminWithNoLinkedChatIsToldSo(): void
    {
        $client = $this->loginAs('alina');
        $this->repositoryWith($this->listing(30_000_000));
        static::getContainer()->set(AdminTelegramChats::class, new AdminTelegramChats('main_admin:1'));

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendVideo');
        static::getContainer()->set(Nutgram::class, $bot);

        $client->request('POST', '/admin/rentals/7/video/send');

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $flash = $client->getRequest()->getSession()->getFlashBag()->peek('error');
        $this->assertStringContainsString('не прив\'язаний до Telegram', $flash[0] ?? '');
    }

    public function testALinkedAdminGetsTheVideoInTheirChat(): void
    {
        $client = $this->loginAs('alina');
        $this->repositoryWith($this->listing(30_000_000));
        static::getContainer()->set(AdminTelegramChats::class, new AdminTelegramChats('alina:471'));

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendVideo')->with('file', 471);
        static::getContainer()->set(Nutgram::class, $bot);

        $client->request('POST', '/admin/rentals/7/video/send');

        $flash = $client->getRequest()->getSession()->getFlashBag()->peek('success');
        $this->assertSame('Відео надіслано вам у бот.', $flash[0] ?? null);
    }

    public function testABotThatCannotWriteToTheAdminSaysWhatToDo(): void
    {
        $client = $this->loginAs('alina');
        $this->repositoryWith($this->listing(30_000_000));
        static::getContainer()->set(AdminTelegramChats::class, new AdminTelegramChats('alina:471'));

        $bot = $this->createMock(Nutgram::class);
        $bot->method('sendVideo')->willThrowException(new \RuntimeException('Forbidden: bot was blocked'));
        static::getContainer()->set(Nutgram::class, $bot);

        $client->request('POST', '/admin/rentals/7/video/send');

        $flash = $client->getRequest()->getSession()->getFlashBag()->peek('error');
        $this->assertStringContainsString('Старт', $flash[0] ?? '');
    }

    public function testThePageOffersAPlayerOrTheButtonBySize(): void
    {
        static::createClient();
        $twig = static::getContainer()->get('twig');

        $small = $twig->render('admin/_rental_video.html.twig', ['listing' => $this->listing(1000)]);
        $this->assertStringContainsString('<video', $small);
        $this->assertStringContainsString('/admin/rentals/7/video', $small);
        $this->assertStringContainsString('1:05', $small);

        $large = $twig->render('admin/_rental_video.html.twig', ['listing' => $this->listing(30_000_000)]);
        $this->assertStringNotContainsString('<video', $large);
        $this->assertStringContainsString('/admin/rentals/7/video/thumb', $large);
        $this->assertStringContainsString('Надіслати мені у бот', $large);
    }
}
