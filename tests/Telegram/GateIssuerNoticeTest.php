<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\GateEvent;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Repository\CarPlateRepository;
use App\Repository\GateEventRepository;
use App\Repository\GuestPassRepository;
use App\Repository\QrScanRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\GateLogService;
use App\Service\GuardService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GateEventCommand;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The flat that issued a pass hears its people leave (17.11.2026).
 *
 * Иван's washing-machine delivery, pass 8: scanned in at 13:51 — «щойно перевірили» — and
 * out at 14:13, with «🚙 Виїзд» pressed and recorded, and the flat heard nothing. The
 * arrival notice is once a day by design; nothing listened to the gate log at all.
 */
class GateIssuerNoticeTest extends KernelTestCase
{
    private const GUARD = '777000';
    private const ISSUER_CHAT = '6707987350';
    private const SECRET = 'test-secret';
    private const SCAN_ID = 26;

    /** Did the guard log this pass going out earlier today, as the repository would answer. */
    private bool $leftEarlier = true;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->leftEarlier = true;
    }

    public function testExitTellsTheIssuerTheyLeft(): void
    {
        $messages = $this->press('out');

        $this->assertCount(2, $messages, 'the guard\'s receipt, then the flat');
        $this->assertStringContainsString('Зафіксовано: 🚙 Виїзд', $messages[0]['text']);
        $this->assertNotSame(self::ISSUER_CHAT, (string)$messages[0]['chat_id'], 'the receipt is the guard\'s');

        $this->assertSame(self::ISSUER_CHAT, (string)$messages[1]['chat_id']);
        $this->assertStringContainsString('Ваші люди виїхали', $messages[1]['text']);
        $this->assertStringContainsString('Доставка пральної машини', $messages[1]['text']);
        $this->assertMatchesRegularExpression('/виїзд о <b>\d\d:\d\d<\/b>/', $messages[1]['text']);
        $this->assertStringNotContainsString('Ситипарк', $messages[1]['text'], 'the guard is not named');
    }

    /** Exit needs no earlier event — the flat was told «перевірили», never «виїхали». */
    public function testExitIsToldWithoutAnyEarlierEvent(): void
    {
        $this->leftEarlier = false;

        $this->assertStringContainsString('Ваші люди виїхали', $this->press('out')[1]['text'] ?? '');
    }

    /**
     * «🚗 В'їзд» with no exit before it — the first entry, a double scan at the door, or a
     * guard's entry after a neighbour's check: the scan already said «щойно перевірили».
     */
    public function testEntryWithoutAnEarlierExitIsNotToldTwice(): void
    {
        $this->leftEarlier = false;

        $messages = $this->press('in');

        $this->assertCount(1, $messages, 'only the guard\'s receipt');
        $this->assertStringContainsString("Зафіксовано: 🚗 В'їзд", $messages[0]['text']);
    }

    /** Back after lunch — out logged earlier today: «🚗 В'їзд» is a fresh fact. */
    public function testReEntryIsTold(): void
    {
        $messages = $this->press('in');

        $this->assertCount(2, $messages);
        $this->assertSame(self::ISSUER_CHAT, (string)$messages[1]['chat_id']);
        $this->assertStringContainsString('знову заїхали', $messages[1]['text']);
    }

    /** A resident's own code has no issuer to tell. */
    public function testAResidentsCodeTellsNobody(): void
    {
        $this->assertCount(1, $this->press('out', guest: false));
    }

    /** An issuer the bot cannot reach: the guard's press still records and confirms. */
    public function testAnIssuerWithoutAChatGetsNothingAndTheGuardIsUnaffected(): void
    {
        $messages = $this->press('out', issuerChat: null);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Зафіксовано: 🚙 Виїзд', $messages[0]['text']);
    }

    // ---------------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> every sendMessage, in order */
    private function press(string $direction, bool $guest = true, ?string $issuerChat = self::ISSUER_CHAT): array
    {
        $account = (new Account())
            ->setAccountNumber('220085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 2);

        $issuer = (new TelegramUser())->setFirstName('Іван')->setTelegramId('5')->setChatId($issuerChat);
        $pass = (new GuestPass())->setAccount($account)->setLabel('Доставка пральної машини')->setIssuedBy($issuer);
        $pass->setActiveUntil(new \DateTime('+2 hours'));
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 8);

        $scan = (new QrScan())
            ->setKind($guest ? QrScan::KIND_GUEST : QrScan::KIND_RESIDENT)
            ->setResult(QrScan::RESULT_OK)
            ->setScannedBy($this->guard())
            ->setByGuard(true)
            ->setSubjectAccount($account)
            ->setSubjectLabel($account->getPlaceLabel())
            ->setGuestPassId($guest ? 8 : null);
        (new \ReflectionProperty(QrScan::class, 'id'))->setValue($scan, self::SCAN_ID);

        $events = $this->createMock(GateEventRepository::class);
        $events->method('forScan')->willReturn(null);
        $events->method('passLeftEarlier')->willReturnCallback(fn () => $this->leftEarlier);

        $scans = $this->createMock(QrScanRepository::class);
        $scans->method('find')->willReturn($scan);

        $passes = $this->createMock(GuestPassRepository::class);
        $passes->method('find')->willReturnCallback(fn ($id) => $id === 8 ? $pass : null);

        $gate = new GateLogService(
            $this->createMock(EntityManagerInterface::class),
            $events,
            $scans,
            $passes,
            $this->createMock(CarPlateRepository::class),
            new GuardService($this->createMock(ScheduledSetRepository::class), self::GUARD, self::SECRET),
            self::SECRET,
        );

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($this->guard());

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());
        $command = new GateEventCommand($gate, $users);
        $bot->onCallbackQueryData(GateLogService::CALLBACK_PATTERN, fn (Nutgram $b) => $command($b));
        $bot->hearCallbackQueryData(sprintf('gate:%s:%d:%s', $direction, self::SCAN_ID, $gate->sign(self::SCAN_ID, self::GUARD)));
        $bot->run();

        $messages = [];

        foreach ($bot->getRequestHistory() as $request) {
            if (basename($request['request']->getUri()->getPath()) === 'sendMessage') {
                $messages[] = json_decode((string)$request['request']->getBody(), true);
            }
        }

        return $messages;
    }

    private function guard(): TelegramUser
    {
        return (new TelegramUser())->setFirstName('Охорона')->setLastName('Ситипарк')->setTelegramId(self::GUARD);
    }
}
