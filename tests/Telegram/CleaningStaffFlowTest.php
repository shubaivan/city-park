<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\ServiceStaff;
use App\Entity\TelegramUser;
use App\Repository\CleaningReportPhotoRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\ServiceStaffRepository;
use App\Service\CleaningReportService;
use App\Service\CleaningStaffService;
use App\Service\PavilionPhotoService;
use App\Service\PhotoUploadFlow;
use App\Service\TelegramUserService;
use App\Telegram\Cleaning\Command\CleaningReportCommand;
use App\Telegram\Start\Command\StartCommand;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cleaning crew (Иван, 16.11.2026): recognised by phone, two-button menu, photos go to
 * the cleaning report — never pavilion evidence, never the resident menu.
 */
class CleaningStaffFlowTest extends KernelTestCase
{
    private function staffService(?ServiceStaff $byPhone, ?ServiceStaff $byId = null): CleaningStaffService
    {
        $repo = $this->createMock(ServiceStaffRepository::class);
        $repo->method('findByPhone')->willReturn($byPhone);
        $repo->method('findActiveByTelegramId')->willReturn($byId);

        return new CleaningStaffService($repo, $this->createMock(EntityManagerInterface::class));
    }

    private function user(string $tgId = '777000111', ?string $phone = '380670000001'): TelegramUser
    {
        $user = (new TelegramUser())->setTelegramId($tgId);
        $user->setPhoneNumber($phone);

        return $user;
    }

    public function testPhoneMatchLinksTheTelegramId(): void
    {
        $row = new ServiceStaff('+380 67 000 00 01');
        $service = $this->staffService($row);

        $this->assertTrue($service->isCleaning($this->user()));
        $this->assertSame('777000111', $row->getTelegramId());
        $this->assertSame('670000001', $row->getPhoneKey());
    }

    public function testInactiveOrUnknownNumberIsNotStaff(): void
    {
        $this->assertFalse($this->staffService(null)->isCleaning($this->user()));
        $this->assertFalse($this->staffService((new ServiceStaff('+380670000001'))->setActive(false))->isCleaning($this->user()));
    }

    public function testAResidentIsNeverTreatedAsStaff(): void
    {
        $user = $this->user();
        $user->setAccount(new Account());

        $this->assertFalse($this->staffService(new ServiceStaff('+380670000001'))->isCleaning($user));
    }

    /** @return string[] */
    private function menu(bool $cleaning): array
    {
        self::bootKernel();
        $container = self::getContainer();

        $staff = $this->createMock(CleaningStaffService::class);
        $staff->method('isCleaning')->willReturn($cleaning);
        $container->set(CleaningStaffService::class, $staff);

        $users = $container->get(TelegramUserService::class);
        (new \ReflectionProperty(TelegramUserService::class, 'currentUser'))->setValue($users, $this->user());

        $markup = (new \ReflectionMethod(StartCommand::class, 'mainMenuMarkup'))
            ->invoke(null, $container->get(Nutgram::class), null);

        $buttons = [];
        foreach ($markup->inline_keyboard as $row) {
            foreach ($row as $button) {
                $buttons[] = $button->text . ' -> ' . ($button->callback_data ?? '?');
            }
        }

        return $buttons;
    }

    public function testCleaningStaffSeeOnlyThePhotoReport(): void
    {
        $this->assertSame(['📷 Фотозвіт -> ' . CleaningReportCommand::MENU_CALLBACK], $this->menu(true));
    }

    public function testAnUnknownVisitorStillGetsThePhoneButton(): void
    {
        $menu = implode("\n", $this->menu(false));

        $this->assertStringNotContainsString('📷 Фотозвіт', $menu);
        $this->assertStringContainsString('📱', $menu);
    }

    public function testStaffPhotoGoesToTheCleaningReportNotThePavilion(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $user = $this->user();
        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($user);

        $row = new ServiceStaff('+380670000001');
        $reports = $this->createMock(CleaningReportService::class);
        $reports->expects($this->once())->method('handle')->with($this->anything(), $row);

        $requests = $this->createMock(PhotoUploadRequestRepository::class);
        $requests->expects($this->never())->method('findOpenForAccount');

        $flow = new PhotoUploadFlow(
            $users,
            $requests,
            $this->createMock(PavilionPhotoService::class),
            new NullLogger(),
            new NullLogger(),
            $this->staffService($row),
            $reports,
        );

        $flow->process($container->get(Nutgram::class));
    }

    public function testUnconfiguredTopicMeansNotConfigured(): void
    {
        $make = fn (string $topic) => new CleaningReportService(
            $this->createMock(CleaningReportPhotoRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class),
            $this->createMock(Nutgram::class),
            new NullLogger(),
            '-1004443785072',
            $topic,
        );

        $this->assertFalse($make('')->isConfigured());
        $this->assertFalse($make('0')->isConfigured());
        $this->assertTrue($make('810')->isConfigured());
        $this->assertSame(810, $make('810')->topic());
    }

    public function testCaptionIsSignedInKyivTimeAndEscaped(): void
    {
        $at = new \DateTimeImmutable('2026-11-16 18:15:00', new \DateTimeZone('UTC'));

        $this->assertSame(
            "🧹 <b>Клінінг</b> · 16.11.2026, 20:15\n\nСходи &lt;буд. 21&gt; &amp; ліфт",
            CleaningReportService::caption('Клінінг', $at, ['Сходи <буд. 21> & ліфт', null, '  ']),
        );
        $this->assertSame('🧹 <b>Клінінг</b> · 16.11.2026, 20:15', CleaningReportService::caption('Клінінг', $at, []));
        $this->assertLessThanOrEqual(1024, mb_strlen(CleaningReportService::caption('Клінінг', $at, [str_repeat('&', 3000)])));
    }

    public function testAnAlbumIsPostedAsOneMediaGroupIntoTheTopic(): void
    {
        $staff = new ServiceStaff('+380670000001');
        $rows = [];
        foreach ([1, 2, 3] as $i) {
            $row = new \App\Entity\CleaningReportPhoto($staff, '777000111', 100 + $i, 'file' . $i, $i === 2 ? 'Під\'їзд 3' : null, 'grp', 'Клінінг');
            (new \ReflectionProperty(\App\Entity\CleaningReportPhoto::class, 'created_at'))->setValue($row, new \DateTime('-10 seconds'));
            $rows[] = $row;
        }

        $repo = $this->createMock(CleaningReportPhotoRepository::class);
        $repo->method('findUnpostedInGroup')->with('grp')->willReturn($rows);

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())->method('sendMediaGroup')->with(
            $this->callback(static fn (array $media): bool => count($media) === 3
                && str_contains((string)$media[0]->caption, 'Під&#039;їзд 3')
                && str_starts_with((string)$media[0]->caption, '🧹 <b>Клінінг</b>')
                && $media[1]->caption === null),
            '-1004443785072',
            810,
        );
        $bot->expects($this->never())->method('sendPhoto');
        $bot->expects($this->once())->method('sendMessage');

        (new CleaningReportService(
            $repo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class),
            $bot,
            new NullLogger(),
            '-1004443785072',
            '810',
        ))->flushAlbum('grp');

        foreach ($rows as $row) {
            $this->assertNotNull($row->getPostedAt());
        }
    }

    public function testAFreshAlbumIsWaitedForNotPostedHalfway(): void
    {
        $row = new \App\Entity\CleaningReportPhoto(new ServiceStaff('+380670000001'), '1', 5, 'f', null, 'grp', 'Клінінг');
        $repo = $this->createMock(CleaningReportPhotoRepository::class);
        $repo->method('findUnpostedInGroup')->willReturn([$row]);

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendMediaGroup');
        $bot->expects($this->never())->method('sendPhoto');

        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturn(new \Symfony\Component\Messenger\Envelope(new \stdClass()));

        (new CleaningReportService($repo, $this->createMock(EntityManagerInterface::class), $bus, $bot, new NullLogger(), '-100', '810'))
            ->flushAlbum('grp');

        $this->assertNull($row->getPostedAt());
    }
}
