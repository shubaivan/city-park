<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\GateEvent;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\CarPlateRepository;
use App\Repository\GateEventRepository;
use App\Repository\GuestPassRepository;
use App\Repository\QrScanRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\GateLogService;
use App\Service\GuardService;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GateEventCommand;
use App\Telegram\Guard\Command\GuardScanCommand;
use App\Telegram\GuestPass\Command\GuestPassScanCommand;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The guard's «🚗 В'їзд / 🚙 Виїзд» end to end through FakeNutgram (Иван, 13.11.2026):
 * the buttons appear under the guard's valid scan and nowhere else, and a press records,
 * settles the message and sends the receipt — once.
 *
 * The resident's half is the point as much as the guard's: **a neighbour's scan must be
 * byte-for-byte what it was** — the same single «🏛 Альтанки зараз» button, nothing new.
 */
class GateScanFlowTest extends KernelTestCase
{
    private const GUARD = '777000';
    private const SECRET = 'test-secret';

    private ?GateEvent $existing = null;
    private int $persisted = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->existing = null;
        $this->persisted = 0;
    }

    public function testTheGuardsValidScanCarriesEntryAndExit(): void
    {
        $markup = $this->residentScan(self::GUARD);

        $this->assertCount(2, $markup, 'the gate row, then the board');
        $this->assertSame(["🚗 В'їзд", '🚙 Виїзд'], array_column($markup[0], 'text'));
        $this->assertMatchesRegularExpression('/^gate:in:41:[a-f0-9]{10}$/', $markup[0][0]['callback_data']);
        $this->assertMatchesRegularExpression('/^gate:out:41:[a-f0-9]{10}$/', $markup[0][1]['callback_data']);
        $this->assertSame('🏛 Альтанки зараз', $markup[1][0]['text']);
    }

    /** No booking is still «✅ Код дійсний», so the gate row is there too. */
    public function testTheGuardGetsTheButtonsWithoutABookingToo(): void
    {
        $markup = $this->residentScan(self::GUARD, booked: false);

        $this->assertStringStartsWith('gate:in:', $markup[0][0]['callback_data']);
    }

    /** The resident's scan is unchanged: one row, one button, no gate anywhere — booked or not. */
    public function testAResidentsScanIsUnchanged(): void
    {
        foreach ([true, false] as $booked) {
            $markup = $this->residentScan('888111', booked: $booked);

            $this->assertSame([[['text' => '🏛 Альтанки зараз', 'callback_data' => 'guard-board']]], $markup);
        }
    }

    /** An unrecognised code gets no buttons even from the guard: there is nobody to log. */
    public function testAnUnknownCodeGetsNoButtons(): void
    {
        $markup = $this->residentScan(self::GUARD, known: false);

        $this->assertStringNotContainsString('gate:', json_encode($markup));
    }

    public function testAValidGuestPassCarriesTheButtonsForTheGuardOnly(): void
    {
        $guard = $this->passScan(self::GUARD, active: true);
        $this->assertSame(["🚗 В'їзд", '🚙 Виїзд'], array_column($guard[0], 'text'));

        $this->assertStringNotContainsString('gate:', json_encode($this->passScan('888111', active: true)));
        $this->assertStringNotContainsString('gate:', json_encode($this->passScan(self::GUARD, active: false)));
    }

    public function testAPressRecordsSettlesTheMessageAndConfirms(): void
    {
        $calls = $this->press(self::GUARD, 'in');

        $this->assertSame(1, $this->persisted);
        $this->assertArrayHasKey('editMessageReplyMarkup', $calls);
        $this->assertStringContainsString("✅ Обрано: 🚗 В'їзд", $calls['editMessageReplyMarkup']);
        $this->assertStringNotContainsString('gate:in:', $calls['editMessageReplyMarkup'], 'the buttons are gone');
        $this->assertStringContainsString("Зафіксовано: 🚗 В'їзд", $calls['sendMessage']);
        $this->assertStringContainsString('Олена Петренко, буд. 19, кв. 85', $calls['sendMessage']);
    }

    public function testASecondPressRecordsNothingAndSaysWhatWasRecorded(): void
    {
        $this->existing = (new GateEvent())->setDirection(GateEvent::DIRECTION_IN);
        $calls = $this->press(self::GUARD, 'out');

        $this->assertSame(0, $this->persisted);
        $this->assertStringContainsString("Вже зафіксовано: 🚗 В'їзд", $calls['answerCallbackQuery']);
        $this->assertArrayNotHasKey('sendMessage', $calls, 'no second receipt');
        $this->assertStringContainsString('✅ Обрано', $calls['editMessageReplyMarkup']);
    }

    public function testSomebodyElsesPressIsRefusedAndChangesNothing(): void
    {
        $calls = $this->press('888111', 'in');

        $this->assertSame(0, $this->persisted);
        $this->assertStringContainsString('лише для охоронця', $calls['answerCallbackQuery']);
        $this->assertArrayNotHasKey('editMessageReplyMarkup', $calls);
        $this->assertArrayNotHasKey('sendMessage', $calls);
    }

    public function testAStalePressIsRefusedAndTheButtonsGo(): void
    {
        $calls = $this->press(self::GUARD, 'in', ageMinutes: GateLogService::TTL_MINUTES + 5);

        $this->assertSame(0, $this->persisted);
        $this->assertStringContainsString('Відскануйте код ще раз', $calls['answerCallbackQuery']);
        $this->assertStringNotContainsString('gate:', $calls['editMessageReplyMarkup']);
        $this->assertArrayNotHasKey('sendMessage', $calls);
    }

    // ---------------------------------------------------------------------------------

    /** @return array<int, array<int, array<string, string>>> the inline keyboard sent */
    private function residentScan(string $scanner, bool $known = true, bool $booked = true): array
    {
        $holder = $this->account();
        $guard = $this->guardWithSession($booked ? $this->session($holder) : null);

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($this->user($scanner));
        $users->method('resolveAccount')->willReturn($this->account(9, '12'));

        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('find')->willReturn($known ? $holder : null);

        $passes = $this->createMock(GuestPassService::class);
        $passes->method('recordScan')->willReturnCallback(
            fn (string $kind, string $result, ?TelegramUser $by, ?Account $subject, $pass, bool $byGuard) => $this->scan($kind, $result, $by, $subject, $byGuard),
        );

        $bot = $this->bot();
        (new GuardScanCommand($guard, $users, $accounts, $passes, null, $this->gate()))($bot, $guard->mintToken($holder));

        return $this->keyboard($bot);
    }

    /** @return array<int, array<int, array<string, string>>> */
    private function passScan(string $scanner, bool $active): array
    {
        $pass = (new GuestPass())->setAccount($this->account())->setLabel('AA1234BB Бригада');
        $pass->setActiveUntil($active ? new \DateTime('+2 hours') : new \DateTime('-1 hour'));
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 3);

        $passes = $this->createMock(GuestPassService::class);
        $passes->method('readToken')->willReturn(3);
        $passes->method('find')->willReturn($pass);
        $passes->method('recordScan')->willReturnCallback(
            fn (string $kind, string $result, ?TelegramUser $by, ?Account $subject, $p, bool $byGuard) => $this->scan($kind, $result, $by, $subject, $byGuard),
        );

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($this->user($scanner));
        $users->method('resolveAccount')->willReturn($this->account(9, '12'));

        $guard = new GuardService($this->createMock(ScheduledSetRepository::class), self::GUARD, self::SECRET);

        $bot = $this->bot();
        (new GuestPassScanCommand($passes, $guard, $users, $this->gate()))($bot, 'p-3-abcdef012345');

        return $this->keyboard($bot);
    }

    /** @return array<string, string> method => request body (the last of each) */
    private function press(string $presser, string $direction, int $ageMinutes = 1): array
    {
        $scan = $this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_OK, $this->user(self::GUARD), $this->account(), true);
        (new \ReflectionProperty(QrScan::class, 'created_at'))
            ->setValue($scan, new \DateTime('-' . $ageMinutes . ' minutes', new \DateTimeZone('Europe/Kyiv')));

        $gate = $this->gate($scan);
        $data = sprintf('gate:%s:41:%s', $direction, $gate->sign(41, self::GUARD));

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($this->user($presser));

        $bot = $this->bot();
        $command = new GateEventCommand($gate, $users);
        $bot->onCallbackQueryData('^gate:(?:(?:in|out):\d+:[a-f0-9]{10}|noop)$', fn (Nutgram $b) => $command($b));
        $bot->hearCallbackQueryData($data);
        $bot->run();

        $calls = [];

        foreach ($bot->getRequestHistory() as $request) {
            $method = basename($request['request']->getUri()->getPath());
            $calls[$method] = (string)$request['request']->getBody();
        }

        return array_map(static fn (string $body) => json_encode(json_decode($body, true), JSON_UNESCAPED_UNICODE) ?: $body, $calls);
    }

    private function guardWithSession(?array $session): GuardService
    {
        // runningSessionFor() goes through the query builder, which a mock cannot answer —
        // the same stub GuardScanAudienceTest uses.
        return new class ($this->createMock(ScheduledSetRepository::class), self::GUARD, self::SECRET, $session) extends GuardService {
            public function __construct(ScheduledSetRepository $bookings, string $ids, string $secret, private ?array $session = null)
            {
                parent::__construct($bookings, $ids, $secret);
            }

            public function runningSessionFor(Account $account, \DateTimeInterface $now): ?array
            {
                return $this->session;
            }
        };
    }

    /** @return array{pavilion:int, start:\DateTimeImmutable, end:\DateTimeImmutable, account:?Account, user:TelegramUser} */
    private function session(Account $account): array
    {
        $day = new \DateTimeImmutable('today 18:00', new \DateTimeZone('Europe/Kyiv'));
        $user = $this->user('1');
        $user->setAccount($account);

        return ['pavilion' => 2, 'start' => $day, 'end' => $day->modify('+3 hours'), 'account' => $account, 'user' => $user];
    }

    private function gate(?QrScan $scan = null): GateLogService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function () {
            $this->persisted++;
        });

        $events = $this->createMock(GateEventRepository::class);
        $events->method('forScan')->willReturnCallback(fn () => $this->existing);

        $scans = $this->createMock(QrScanRepository::class);
        $scans->method('find')->willReturn($scan);

        return new GateLogService(
            $em,
            $events,
            $scans,
            $this->createMock(GuestPassRepository::class),
            $this->createMock(CarPlateRepository::class),
            new GuardService($this->createMock(ScheduledSetRepository::class), self::GUARD, self::SECRET),
            self::SECRET,
        );
    }

    private function scan(string $kind, string $result, ?TelegramUser $by, ?Account $subject, bool $byGuard): QrScan
    {
        $scan = (new QrScan())
            ->setKind($kind)
            ->setResult($result)
            ->setScannedBy($by)
            ->setByGuard($byGuard)
            ->setSubjectAccount($subject)
            ->setSubjectLabel($subject?->getPlaceLabel() ?? '—');
        (new \ReflectionProperty(QrScan::class, 'id'))->setValue($scan, 41);

        return $scan;
    }

    private function bot(): FakeNutgram
    {
        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());

        return $bot;
    }

    /** @return array<int, array<int, array<string, string>>> */
    private function keyboard(Nutgram $bot): array
    {
        $history = $bot->getRequestHistory();
        $fields = json_decode((string)end($history)['request']->getBody(), true);
        $markup = is_string($fields['reply_markup'] ?? null) ? json_decode($fields['reply_markup'], true) : ($fields['reply_markup'] ?? []);

        // Only what the guard sees: label and callback.
        return array_map(
            static fn (array $row) => array_map(
                static fn (array $b) => array_intersect_key($b, ['text' => 1, 'callback_data' => 1]),
                $row,
            ),
            $markup['inline_keyboard'] ?? [],
        );
    }

    private function account(int $id = 7, string $apartment = '85'): Account
    {
        $account = (new Account())
            ->setAccountNumber('2200' . $apartment)
            ->setApartmentNumber($apartment)
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        $account->setIsActive(true);
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        $owner = (new TelegramUser())->setFirstName('Олена')->setLastName('Петренко');
        $owner->setTelegramId('5');
        $account->getUsers()->add($owner);

        return $account;
    }

    private function user(string $telegramId): TelegramUser
    {
        return (new TelegramUser())->setFirstName('Охорона')->setLastName('Ситипарк')->setTelegramId($telegramId);
    }
}
