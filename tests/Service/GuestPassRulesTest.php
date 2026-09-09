<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Repository\GuestPassRepository;
use App\Repository\QrScanRepository;
use App\Service\GuestPassService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;

/**
 * The rules of the builders' pass — the ones somebody will be tempted to "fix" later.
 *
 * The whole design turns on one decision: **the code is permanent and the day is switched
 * on**, rather than a fresh code every morning. Иван asked for a one-day pass, which is
 * right — a month-long pass circulates and a lost screenshot stays a key. But minting a new
 * one daily would mean re-sending the picture to the бригадир daily, and on the third
 * morning they stop looking at it and ask the guard to take their word again. The lifetime
 * is enforced by the *answer* at the gate, not by the code's existence.
 */
class GuestPassRulesTest extends TestCase
{
    private function service(?GuestPassRepository $passes = null, ?QrScanRepository $scans = null): GuestPassService
    {
        return new GuestPassService(
            $passes ?? $this->createMock(GuestPassRepository::class),
            $scans ?? $this->createMock(QrScanRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(Nutgram::class),
            new NullLogger(),
            'test-secret',
        );
    }

    private function account(int $id = 7): Account
    {
        $account = (new Account())
            ->setAccountNumber('220085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    private function pass(int $id = 3): GuestPass
    {
        $pass = (new GuestPass())->setAccount($this->account())->setLabel('Бригада, ремонт');

        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, $id);

        return $pass;
    }

    /**
     * A pass is on inside the window its host switched on, and off outside it.
     *
     * The window is a moment rather than a day because a delivery is two hours and a
     * renovation is all day — «до кінця дня» handed to a courier is a key they keep until
     * midnight (Иван, 09.09.2026: «предусмотреть выдачу на пару часов»).
     */
    public function testAPassIsValidOnlyInsideItsWindow(): void
    {
        $pass = $this->pass();
        $now = new \DateTime('2026-09-09 08:12', new \DateTimeZone('Europe/Kyiv'));

        $this->assertFalse($pass->isActiveAt($now), 'a fresh pass is off until somebody switches it on');

        $pass->setActiveUntil(new \DateTime('2026-09-09 10:12', new \DateTimeZone('Europe/Kyiv')));

        $this->assertTrue($pass->isActiveAt($now));
        $this->assertFalse(
            $pass->isActiveAt(new \DateTime('2026-09-09 10:13', new \DateTimeZone('Europe/Kyiv'))),
            'a two-hour pass stops in two hours',
        );
        $this->assertFalse(
            $pass->isActiveAt(new \DateTime('2026-09-10 08:12', new \DateTimeZone('Europe/Kyiv'))),
            'yesterday’s screenshot must read as wrong at the gate — that is the whole security model',
        );
    }

    /**
     * A window never crosses midnight, however many hours are asked for.
     *
     * Four hours at 22:00 would otherwise run to 02:00, which is a second day nobody chose
     * — and «один день» is the outer limit the whole feature was asked for with.
     */
    public function testAWindowIsClampedToTheEndOfTheDay(): void
    {
        $pass = $this->pass();
        $service = $this->service();

        $service->activate($pass, 24);

        $until = $pass->getActiveUntil();
        $this->assertNotNull($until);
        $this->assertSame(
            (new \DateTime('today', new \DateTimeZone('Europe/Kyiv')))->format('Y-m-d'),
            $until->format('Y-m-d'),
            'a window may not run into tomorrow',
        );
        $this->assertSame('23:59', $until->format('H:i'));
    }

    /** Switched off now, not revoked: tomorrow the same picture works again. */
    public function testItCanBeSwitchedOffWithoutBeingRevoked(): void
    {
        $pass = $this->pass();
        $service = $this->service();

        $service->activate($pass);
        $this->assertTrue($pass->isActiveAt(new \DateTime('now', new \DateTimeZone('Europe/Kyiv'))));

        $service->deactivate($pass);

        $this->assertFalse($pass->isActiveAt(new \DateTime('now', new \DateTimeZone('Europe/Kyiv'))));
        $this->assertFalse($pass->isRevoked(), 'switching off is not withdrawing');
    }

    /** Revoked is dead, window or not: the picture in somebody's phone stops working. */
    public function testARevokedPassIsDeadEvenInsideItsWindow(): void
    {
        $pass = $this->pass();
        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $pass->setActiveUntil(new \DateTime('+1 hour', new \DateTimeZone('Europe/Kyiv')));

        $this->assertTrue($pass->isActiveAt($now));

        $pass->revoke();

        $this->assertTrue($pass->isRevoked());
        $this->assertFalse($pass->isActiveAt($now));
    }

    /** Three live passes per flat: a crew, a delivery and a fitter, not a street. */
    public function testTheCapIsThreeLivePassesPerFlat(): void
    {
        $account = $this->account();

        $repo = $this->createMock(GuestPassRepository::class);
        $repo->method('liveFor')->willReturn(array_fill(0, GuestPass::MAX_PER_ACCOUNT, $this->pass()));

        $this->assertFalse($this->service($repo)->mayCreate($account));

        $repo = $this->createMock(GuestPassRepository::class);
        $repo->method('liveFor')->willReturn([$this->pass()]);

        $this->assertTrue($this->service($repo)->mayCreate($account));
        $this->assertFalse($this->service($repo)->mayCreate(null), 'no flat, nothing to vouch for anybody');
    }

    /**
     * The token is signed, like the resident's own code.
     *
     * A bare id could be guessed, and a guard would then confirm somebody nobody invited —
     * exactly the hole the resident QR's signature was added for.
     */
    public function testTheTokenIsSignedAndUnforgeable(): void
    {
        $service = $this->service();
        $token = $service->mintToken($this->pass(3));

        $this->assertSame(3, $service->readToken($token));
        $this->assertNull($service->readToken('p-3-000000000000'), 'a wrong signature is not one of ours');
        $this->assertNull($service->readToken('p-4-' . explode('-', $token)[2]), 'a signature is per pass');
        $this->assertNull($service->readToken('g-3-abcdef012345'), 'the resident’s prefix is a different code');
        $this->assertNull($service->readToken('nonsense'));
    }

    /**
     * The flat is told when its crew arrives — once.
     *
     * «Тем самым будет знать когда пришли строители» is half of why this feature is worth
     * having. A guard who checks the same people twice at the door has not made them arrive
     * twice, so only the first valid scan of the day speaks; a second message would teach
     * the resident to mute the first.
     */
    public function testOnlyTheFirstValidScanOfTheDayIsAnArrival(): void
    {
        $scans = $this->createMock(QrScanRepository::class);
        $scans->expects($this->once())->method('passWasScannedToday')->willReturn(true);

        // With an earlier scan today, nothing is sent: the mocked Nutgram would fail the
        // test by being called at all.
        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendMessage');

        $service = new GuestPassService(
            $this->createMock(GuestPassRepository::class),
            $scans,
            $this->createMock(EntityManagerInterface::class),
            $bot,
            new NullLogger(),
            'test-secret',
        );

        $service->recordScan(QrScan::KIND_GUEST, QrScan::RESULT_OK, null, $this->account(), $this->pass());
    }
}
