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
 * Passes switched on for whole days, up to a week (Иван, 18.11.2026).
 *
 * A pass used to be on for one day at most, which left the flat pressing «🔁 Активувати» every
 * morning of a five-day renovation. Now a window can be 1–7 calendar days counting today,
 * always ending 23:59:59 Kyiv on the last day. What must not change with it: the window is
 * checked on every scan, the flat is told once per day per pass, and an hour window still
 * never crosses midnight.
 */
class GuestPassMultiDayTest extends TestCase
{
    private const KYIV = 'Europe/Kyiv';

    private function service(?QrScanRepository $scans = null, ?Nutgram $bot = null, ?GuestPassRepository $passes = null): GuestPassService
    {
        return new GuestPassService(
            $passes ?? $this->createMock(GuestPassRepository::class),
            $scans ?? $this->createMock(QrScanRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $bot ?? $this->createMock(Nutgram::class),
            new NullLogger(),
            'test-secret',
        );
    }

    private function account(): Account
    {
        $account = (new Account())->setAccountNumber('220085')->setApartmentNumber('85')->setHouseNumber('19');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 7);

        return $account;
    }

    private function pass(): GuestPass
    {
        $pass = (new GuestPass())->setAccount($this->account())->setLabel('Бригада, ремонт');
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 3);

        return $pass;
    }

    private static function kyiv(string $at): \DateTime
    {
        return new \DateTime($at, new \DateTimeZone(self::KYIV));
    }

    /** Today counts as day one; every window ends 23:59:59 Kyiv on its last day. */
    public function testDaysCountTodayAndEndAt2359Kyiv(): void
    {
        $now = self::kyiv('2026-11-18 10:15');

        $this->assertSame('2026-11-18 23:59:59', GuestPassService::endOfDays(1, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-19 23:59:59', GuestPassService::endOfDays(2, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 23:59:59', GuestPassService::endOfDays(3, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-24 23:59:59', GuestPassService::endOfDays(7, $now)->format('Y-m-d H:i:s'));
        $this->assertSame(self::KYIV, GuestPassService::endOfDays(7, $now)->getTimezone()->getName());
    }

    /** The number comes from a callback: a hand-made `pass:days:<id>:365` is still a week. */
    public function testTheLengthIsClampedToOneWeek(): void
    {
        $now = self::kyiv('2026-11-18 10:15');

        $this->assertSame(7, GuestPass::MAX_DAYS);
        $this->assertSame('2026-11-24 23:59:59', GuestPassService::endOfDays(365, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-18 23:59:59', GuestPassService::endOfDays(0, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-18 23:59:59', GuestPassService::endOfDays(-5, $now)->format('Y-m-d H:i:s'));

        foreach (array_keys(GuestPassService::DAY_OPTIONS) as $days) {
            $this->assertLessThanOrEqual(GuestPass::MAX_DAYS, $days, 'no button may offer more than the cap');
        }
        $this->assertSame(1, array_key_first(GuestPassService::DAY_OPTIONS), '«Сьогодні» is the first, default choice');
    }

    /** The «now» of a UTC server late in the evening is already tomorrow in Kyiv. */
    public function testTheDayIsTheKyivDayWhateverZoneNowIsIn(): void
    {
        // 22:30 UTC on the 18th is 00:30 Kyiv on the 19th.
        $now = new \DateTime('2026-11-18 22:30', new \DateTimeZone('UTC'));

        $this->assertSame('2026-11-19 23:59:59', GuestPassService::endOfDays(1, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-25 23:59:59', GuestPassService::endOfDays(7, $now)->format('Y-m-d H:i:s'));
    }

    /** A week across the clock change still ends at 23:59 local, not 22:59 or 00:59. */
    public function testAWeekAcrossTheClockChangeEndsAt2359Local(): void
    {
        foreach (['2026-10-22 09:00' => '2026-10-28', '2027-03-25 09:00' => '2027-03-31'] as $start => $lastDay) {
            $end = GuestPassService::endOfDays(7, self::kyiv($start));

            $this->assertSame($lastDay . ' 23:59:59', $end->format('Y-m-d H:i:s'), $start);
        }
    }

    /** Checked on every scan: valid through 23:59 of the last day, dead at 00:00 after it. */
    public function testAWeekPassIsValidUntilTheLastMinuteAndNotAfter(): void
    {
        $pass = $this->pass();
        $pass->setActiveUntil(GuestPassService::endOfDays(7, self::kyiv('2026-11-18 10:15')));

        $this->assertTrue($pass->isActiveAt(self::kyiv('2026-11-18 10:16')));
        $this->assertTrue($pass->isActiveAt(self::kyiv('2026-11-21 03:00')), 'night in the middle of the window');
        $this->assertTrue($pass->isActiveAt(self::kyiv('2026-11-24 23:59:00')));
        $this->assertFalse($pass->isActiveAt(self::kyiv('2026-11-25 00:00:00')), 'day eight is outside');

        $pass->revoke();
        $this->assertFalse($pass->isActiveAt(self::kyiv('2026-11-20 12:00')), 'revoking still kills it early');
    }

    /** As Doctrine hydrates it — a bare wall clock in the default zone — it means the same. */
    public function testAHydratedEndIsReadAsKyiv(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set(self::KYIV);

        try {
            $pass = $this->pass();
            $pass->setActiveUntil(new \DateTime('2026-11-24 23:59:59'));

            $this->assertTrue($pass->isActiveAt(self::kyiv('2026-11-24 23:30')));
            $this->assertFalse($pass->isActiveAt(self::kyiv('2026-11-25 00:00:01')));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testCreateDefaultsToTodayAndTakesALength(): void
    {
        $repo = $this->createMock(GuestPassRepository::class);
        $repo->method('liveFor')->willReturn([]);
        $service = $this->service(passes: $repo);
        $today = new \DateTime('now', new \DateTimeZone(self::KYIV));

        $default = $service->create($this->account(), null, 'Доставка');
        $this->assertNotNull($default);
        $this->assertSame($today->format('Y-m-d') . ' 23:59:59', $default->getActiveUntil()?->format('Y-m-d H:i:s'));

        $week = $service->create($this->account(), null, 'Бригада', 7);
        $this->assertNotNull($week);
        $this->assertSame(
            (clone $today)->setTime(23, 59, 59)->modify('+6 days')->format('Y-m-d H:i:s'),
            $week->getActiveUntil()?->format('Y-m-d H:i:s'),
        );
    }

    /** The card's «📅 3 дні» on a pass that is off, on, or already on for a week. */
    public function testActivateDaysSetsAndShortensButNeverRevives(): void
    {
        $service = $this->service();
        $pass = $this->pass();
        $today = new \DateTime('now', new \DateTimeZone(self::KYIV));

        $service->activateDays($pass, 7);
        $this->assertTrue(GuestPassService::runsPastToday($pass));

        $service->activateDays($pass, 1);
        $this->assertSame($today->format('Y-m-d') . ' 23:59:59', $pass->getActiveUntil()?->format('Y-m-d H:i:s'));
        $this->assertFalse(GuestPassService::runsPastToday($pass));

        // An hour window replaces a week: «⏱ 2 години» on the card means two hours.
        $service->activateDays($pass, 7);
        $service->activate($pass, 2);
        $this->assertFalse(GuestPassService::runsPastToday($pass), 'an hour window never runs into tomorrow');

        $revoked = $this->pass()->revoke();
        $service->activateDays($revoked, 7);
        $this->assertNull($revoked->getActiveUntil(), 'a revoked pass cannot be switched back on');
    }

    /** «сьогодні до 14:20» on its own day; the date when it runs past today. */
    public function testTheEndIsSaidWithItsDateWhenItIsNotToday(): void
    {
        $now = self::kyiv('2026-11-18 10:15');
        $pass = $this->pass();

        $pass->setActiveUntil(self::kyiv('2026-11-18 14:20'));
        $this->assertSame('сьогодні до 14:20', GuestPassService::untilLabel($pass, $now));

        $pass->setActiveUntil(GuestPassService::endOfDays(3, $now));
        $this->assertSame('до 20.11 о 23:59', GuestPassService::untilLabel($pass, $now));
        $this->assertTrue(GuestPassService::runsPastToday($pass, $now));
    }

    /**
     * A pass made before 18.11.2026 is a one-day pass and reads exactly as before.
     * No column changed: its `active_until` is 23:59:59 of the day it was switched on.
     */
    public function testAnExistingOneDayPassIsUnaffected(): void
    {
        // As prod hydrates it: Kernel sets Europe/Kyiv as PHP's zone (ApplicationTimezoneTest).
        $previous = date_default_timezone_get();
        date_default_timezone_set(self::KYIV);

        try {
            $now = self::kyiv('2026-11-17 13:51');
            $pass = $this->pass();
            $pass->setActiveUntil(new \DateTime('2026-11-17 23:59:59'));

            $this->assertFalse(GuestPassService::runsPastToday($pass, $now));
            $this->assertSame('сьогодні до 23:59', GuestPassService::untilLabel($pass, $now));
            $this->assertFalse($pass->isActiveAt(self::kyiv('2026-11-18 00:00:00')));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * On day two of a week-long pass, the first valid scan tells the flat again — once a
     * day per pass — and the message says until when the pass stays open.
     */
    public function testEachDaysFirstScanOfAMultiDayPassNotifiesTheFlatWithTheEnd(): void
    {
        $scans = $this->createMock(QrScanRepository::class);
        $scans->expects($this->once())
            ->method('passWasScannedToday')
            ->with(3, $this->callback(static fn (\DateTimeInterface $d): bool
                => $d->format('Y-m-d') === (new \DateTime('now', new \DateTimeZone(self::KYIV)))->format('Y-m-d')))
            ->willReturn(false);

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(static fn (string $text): bool
                => str_contains($text, 'щойно перевірили') && str_contains($text, 'Пропуск діє до ')));

        $issuer = (new \App\Entity\TelegramUser())->setTelegramId('777')->setChatId('777');
        $pass = $this->pass()->setIssuedBy($issuer);
        $pass->setActiveUntil(GuestPassService::endOfDays(7));

        $this->service($scans, $bot)->recordScan(QrScan::KIND_GUEST, QrScan::RESULT_OK, null, $this->account(), $pass, true);
    }

    /** …and a second scan the same day stays silent, multi-day or not. */
    public function testASecondScanTheSameDayStaysSilent(): void
    {
        $scans = $this->createMock(QrScanRepository::class);
        $scans->method('passWasScannedToday')->willReturn(true);

        $bot = $this->createMock(Nutgram::class);
        $bot->expects($this->never())->method('sendMessage');

        $issuer = (new \App\Entity\TelegramUser())->setTelegramId('777')->setChatId('777');
        $pass = $this->pass()->setIssuedBy($issuer);
        $pass->setActiveUntil(GuestPassService::endOfDays(7));

        $this->service($scans, $bot)->recordScan(QrScan::KIND_GUEST, QrScan::RESULT_OK, null, $this->account(), $pass, true);
    }
}
