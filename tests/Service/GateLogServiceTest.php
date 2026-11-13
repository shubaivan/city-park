<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Entity\GateEvent;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Repository\CarPlateRepository;
use App\Repository\GateEventRepository;
use App\Repository\GuestPassRepository;
use App\Repository\QrScanRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\GateLogService;
use App\Service\GatePress;
use App\Service\GuardService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * «🚗 В'їзд / 🚙 Виїзд» (Иван, 13.11.2026): who may press, when, and how many times.
 *
 * Every refusal must be decided before anything is written — so each case below also
 * asserts on whether `persist()` was reached.
 */
class GateLogServiceTest extends TestCase
{
    private const GUARD = '777000';
    private const OTHER_GUARD = '777001';
    private const SECRET = 'test-secret';

    private ?GateEvent $existing = null;
    private ?QrScan $scan = null;
    private int $persisted = 0;

    protected function setUp(): void
    {
        $this->existing = null;
        $this->persisted = 0;
        $this->scan = $this->scan(self::GUARD);
    }

    public function testTheGuardGetsTwoButtonsBoundToTheScan(): void
    {
        $buttons = $this->service()->buttonsFor($this->scan, $this->user(self::GUARD));

        $this->assertCount(2, $buttons);
        $this->assertSame("🚗 В'їзд", $buttons[0]->text);
        $this->assertSame('🚙 Виїзд', $buttons[1]->text);
        $this->assertMatchesRegularExpression('/^gate:in:41:[a-f0-9]{10}$/', $buttons[0]->callback_data);
        $this->assertMatchesRegularExpression('/^gate:out:41:[a-f0-9]{10}$/', $buttons[1]->callback_data);
        $this->assertLessThanOrEqual(64, strlen($buttons[1]->callback_data), 'Telegram caps callback_data at 64 bytes');
    }

    public function testNobodyButTheGuardGetsButtons(): void
    {
        $this->assertNull($this->service()->buttonsFor($this->scan, $this->user('888111')));
        $this->assertNull($this->service()->buttonsFor($this->scan, null));
    }

    public function testOnlyAValidScanGetsButtons(): void
    {
        foreach ([QrScan::RESULT_UNKNOWN, QrScan::RESULT_REVOKED, QrScan::RESULT_NOT_ACTIVE] as $result) {
            $this->scan->setResult($result);
            $this->assertNull($this->service()->buttonsFor($this->scan, $this->user(self::GUARD)), $result);
        }

        // A resident's code without a booking is still «✅ Код дійсний».
        $this->scan->setResult(QrScan::RESULT_NO_BOOKING);
        $this->assertNotNull($this->service()->buttonsFor($this->scan, $this->user(self::GUARD)));

        // A guest pass: only inside its window.
        $this->scan->setKind(QrScan::KIND_GUEST)->setResult(QrScan::RESULT_NO_BOOKING);
        $this->assertNull($this->service()->buttonsFor($this->scan, $this->user(self::GUARD)));
        $this->scan->setResult(QrScan::RESULT_OK);
        $this->assertNotNull($this->service()->buttonsFor($this->scan, $this->user(self::GUARD)));
    }

    /** An unsaved scan has no id to bind to — no buttons rather than buttons that fail. */
    public function testAScanThatWasNotWrittenGetsNoButtons(): void
    {
        $this->assertNull($this->service()->buttonsFor(null, $this->user(self::GUARD)));
        $this->assertNull($this->service()->buttonsFor(new QrScan(), $this->user(self::GUARD)));
    }

    public function testAPressRecordsWhoWhenWhichWayTheFlatAndTheCar(): void
    {
        $press = $this->press('in', self::GUARD);

        $this->assertSame(GatePress::RECORDED, $press->status);
        $this->assertSame(1, $this->persisted);

        $event = $press->event;
        $this->assertSame(GateEvent::DIRECTION_IN, $event->getDirection());
        $this->assertSame($this->scan, $event->getQrScan());
        $this->assertSame(self::GUARD, $event->getGuard()?->getTelegramId());
        $this->assertSame('буд. 19, кв. 85', $event->getPlaceLabel());
        $this->assertSame('Олена Петренко', $event->getPersonLabel());
        $this->assertSame('CA 1234 AB', $event->getCarLabel());

        $text = GateLogService::confirmation($event);
        $this->assertStringContainsString("Зафіксовано: 🚗 В'їзд", $text);
        $this->assertStringContainsString('Олена Петренко, буд. 19, кв. 85, авто CA 1234 AB, ' . $event->getCreatedAt()->format('H:i'), $text);
    }

    /** No car written down — the confirmation simply has no «авто». */
    public function testNoCarMeansNoCarInTheConfirmation(): void
    {
        $press = $this->press('out', self::GUARD, plates: []);

        $this->assertNull($press->event->getCarLabel());
        $this->assertStringNotContainsString('авто', GateLogService::confirmation($press->event));
        $this->assertStringContainsString('Зафіксовано: 🚙 Виїзд', GateLogService::confirmation($press->event));
    }

    /** Idempotent per scan: the second tap — either button — writes nothing and names the first. */
    public function testASecondPressIsTheFirstOne(): void
    {
        $this->existing = (new GateEvent())->setDirection(GateEvent::DIRECTION_IN)->setQrScan($this->scan);

        foreach (['in', 'out'] as $direction) {
            $press = $this->press($direction, self::GUARD);
            $this->assertSame(GatePress::ALREADY, $press->status);
            $this->assertSame($this->existing, $press->event);
        }

        $this->assertSame(0, $this->persisted);
    }

    /** Somebody else's thumb — a resident the message was forwarded to, or another guard. */
    public function testAnotherUserCannotPress(): void
    {
        $data = $this->data('in', self::GUARD);

        foreach (['888111', self::OTHER_GUARD] as $who) {
            $press = $this->service(self::GUARD . ',' . self::OTHER_GUARD)->press($data, $this->user($who), $this->now());
            $this->assertSame(GatePress::FORBIDDEN, $press->status, $who);
        }

        $this->assertSame(0, $this->persisted);
    }

    /** A signature minted for his own id still does not open another guard's scan. */
    public function testAGuardCannotPressUnderAnotherGuardsScan(): void
    {
        $this->scan = $this->scan(self::OTHER_GUARD);
        $press = $this->service(self::GUARD . ',' . self::OTHER_GUARD)
            ->press($this->data('in', self::GUARD), $this->user(self::GUARD), $this->now());

        $this->assertSame(GatePress::FORBIDDEN, $press->status);
        $this->assertSame(0, $this->persisted);
    }

    public function testATamperedButtonIsRefused(): void
    {
        $press = $this->service()->press('gate:in:41:0000000000', $this->user(self::GUARD), $this->now());

        $this->assertSame(GatePress::FORBIDDEN, $press->status);
        $this->assertSame(GatePress::INVALID, $this->service()->press('gate:in:41', $this->user(self::GUARD), $this->now())->status);
        $this->assertSame(0, $this->persisted);
    }

    /** Taken off GUARD_TELEGRAM_IDS between the scan and the press. */
    public function testAnIdThatLeftTheListCannotPress(): void
    {
        $press = $this->service('999999')->press($this->data('in', self::GUARD), $this->user(self::GUARD), $this->now());

        $this->assertSame(GatePress::FORBIDDEN, $press->status);
        $this->assertSame(0, $this->persisted);
    }

    public function testAStaleButtonIsRefused(): void
    {
        $this->backdate(GateLogService::TTL_MINUTES + 1);
        $this->assertSame(GatePress::EXPIRED, $this->press('in', self::GUARD)->status);
        $this->assertSame(0, $this->persisted);

        $this->backdate(GateLogService::TTL_MINUTES - 1);
        $this->assertSame(GatePress::RECORDED, $this->press('in', self::GUARD)->status);
    }

    public function testAnInvalidScanCannotBeRecorded(): void
    {
        $this->scan->setResult(QrScan::RESULT_REVOKED);

        $this->assertSame(GatePress::INVALID, $this->press('in', self::GUARD)->status);
        $this->assertSame(0, $this->persisted);
    }

    // ---------------------------------------------------------------------------------

    /** @param CarPlate[]|null $plates */
    private function press(string $direction, string $who, ?array $plates = null): GatePress
    {
        return $this->service(plates: $plates)->press($this->data($direction, self::GUARD), $this->user($who), $this->now());
    }

    private function data(string $direction, string $signedFor): string
    {
        return sprintf('gate:%s:41:%s', $direction, $this->service()->sign(41, $signedFor));
    }

    /** @param CarPlate[]|null $plates */
    private function service(string $guardIds = self::GUARD, ?array $plates = null): GateLogService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function () {
            $this->persisted++;
        });

        $events = $this->createMock(GateEventRepository::class);
        $events->method('forScan')->willReturnCallback(fn () => $this->existing);

        $scans = $this->createMock(QrScanRepository::class);
        $scans->method('find')->willReturnCallback(fn (int $id) => $id === 41 ? $this->scan : null);

        $plate = (new CarPlate())->setPlate('CA1234AB');
        $carPlates = $this->createMock(CarPlateRepository::class);
        $carPlates->method('forAccount')->willReturn($plates ?? [$plate]);

        return new GateLogService(
            $em,
            $events,
            $scans,
            $this->createMock(GuestPassRepository::class),
            $carPlates,
            new GuardService($this->createMock(ScheduledSetRepository::class), $guardIds, self::SECRET),
            self::SECRET,
        );
    }

    private function scan(string $scannedBy): QrScan
    {
        $account = (new Account())
            ->setAccountNumber('220085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 7);
        $owner = (new TelegramUser())->setFirstName('Олена')->setLastName('Петренко');
        $owner->setTelegramId('5');
        $account->getUsers()->add($owner);

        $scan = (new QrScan())
            ->setKind(QrScan::KIND_RESIDENT)
            ->setResult(QrScan::RESULT_OK)
            ->setScannedBy($this->user($scannedBy))
            ->setByGuard(true)
            ->setSubjectAccount($account)
            ->setSubjectLabel($account->getPlaceLabel());
        (new \ReflectionProperty(QrScan::class, 'id'))->setValue($scan, 41);

        return $scan;
    }

    private function backdate(int $minutes): void
    {
        (new \ReflectionProperty(QrScan::class, 'created_at'))
            ->setValue($this->scan, new \DateTime('-' . $minutes . ' minutes', new \DateTimeZone('Europe/Kyiv')));
    }

    private function user(string $telegramId): TelegramUser
    {
        return (new TelegramUser())->setFirstName('Охорона')->setLastName('Ситипарк')->setTelegramId($telegramId);
    }

    private function now(): \DateTimeInterface
    {
        return new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
    }
}
