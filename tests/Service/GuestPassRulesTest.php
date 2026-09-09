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

    /** A pass is on for the day it was switched on, and off every other day. */
    public function testAPassIsValidOnlyOnTheDayItWasActivated(): void
    {
        $pass = $this->pass();
        $today = new \DateTime('2026-09-09 08:12', new \DateTimeZone('Europe/Kyiv'));
        $tomorrow = new \DateTime('2026-09-10 08:12', new \DateTimeZone('Europe/Kyiv'));

        $this->assertFalse($pass->isActiveOn($today), 'a fresh pass is off until somebody switches it on');

        $pass->setActiveOn(new \DateTime('2026-09-09', new \DateTimeZone('Europe/Kyiv')));

        $this->assertTrue($pass->isActiveOn($today));
        $this->assertFalse(
            $pass->isActiveOn($tomorrow),
            'yesterday’s screenshot must read as wrong at the gate — that is the whole security model',
        );
    }

    /** Revoked is dead, activated or not: the picture in somebody's phone stops working. */
    public function testARevokedPassIsDeadEvenOnItsOwnDay(): void
    {
        $pass = $this->pass();
        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $pass->setActiveOn(new \DateTime('now', new \DateTimeZone('Europe/Kyiv')));

        $this->assertTrue($pass->isActiveOn($now));

        $pass->revoke();

        $this->assertTrue($pass->isRevoked());
        $this->assertFalse($pass->isActiveOn($now));
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
