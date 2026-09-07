<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Service\GuardService;
use PHPUnit\Framework\TestCase;

/**
 * The gate's board: who it admits, what it groups, and what it must never leave out.
 */
class GuardBoardRulesTest extends TestCase
{
    private function service(string $ids = ''): GuardService
    {
        // The repository is only reached by sessionsOfDay(); every rule below lives in the
        // static half precisely so it can be exercised without a database.
        return new GuardService(
            $this->createMock(\App\Repository\ScheduledSetRepository::class),
            $ids,
        );
    }

    private function user(int $accountId, string $telegramId = '1'): TelegramUser
    {
        $account = new Account();
        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($account, $accountId);
        $account->setAccountNumber('2200' . str_pad((string)$accountId, 2, '0', STR_PAD_LEFT));
        $account->setHouseNumber('19');
        $account->setApartmentNumber((string)(40 + $accountId));

        $user = new TelegramUser();
        $user->setTelegramId($telegramId);
        $user->setAccount($account);

        $uref = new \ReflectionProperty(TelegramUser::class, 'id');
        $uref->setAccessible(true);
        $uref->setValue($user, $accountId);

        return $user;
    }

    private function set(TelegramUser $user, int $pavilion, int $hour): ScheduledSet
    {
        $set = new ScheduledSet();
        $set->setTelegramUserId($user);
        $set->setPavilion($pavilion);
        $set->setYear(2026);
        $set->setMonth(9);
        $set->setDay(7);
        $set->setHour($hour);
        $set->setScheduledAt(new \DateTime(sprintf('2026-09-07 %02d:00:00', $hour), new \DateTimeZone('Europe/Kyiv')));

        return $set;
    }

    /**
     * The rule that matters most in this file: an unset list is nobody.
     *
     * This board names which flat is sitting in which pavilion at what time. A default
     * that opened it to everybody who pressed /start would be a privacy leak that looks
     * exactly like the feature working.
     */
    public function testAnEmptyGuardListMeansNobody(): void
    {
        $guard = $this->service('');

        $this->assertFalse($guard->isConfigured());
        $this->assertFalse($guard->isGuard($this->user(1, '777')));
        $this->assertFalse($guard->isGuard(null));
    }

    public function testOnlyTheConfiguredIdsAreGuards(): void
    {
        $guard = $this->service(' 777 , 888 ');

        $this->assertTrue($guard->isConfigured());
        $this->assertTrue($guard->isGuard($this->user(1, '777')));
        $this->assertTrue($guard->isGuard($this->user(2, '888')));
        $this->assertFalse($guard->isGuard($this->user(3, '999')));
    }

    /** Consecutive hours of one household on one pavilion are one evening, not three. */
    public function testConsecutiveHoursOfTheSameFlatAreOneSession(): void
    {
        $user = $this->user(1);
        $sessions = GuardService::group([
            $this->set($user, 1, 20),
            $this->set($user, 1, 21),
            $this->set($user, 1, 22),
        ]);

        $this->assertCount(1, $sessions);
        $this->assertSame('20:00', $sessions[0]['start']->format('H:i'));
        $this->assertSame('23:00', $sessions[0]['end']->format('H:i'));
    }

    /** A gap splits them: 20:00 and 22:00 are two different groups of people. */
    public function testAGapSplitsASession(): void
    {
        $user = $this->user(1);
        $sessions = GuardService::group([
            $this->set($user, 1, 20),
            $this->set($user, 1, 22),
        ]);

        $this->assertCount(2, $sessions);
    }

    /**
     * Two flats booking back to back must never be merged — the guard would then read one
     * line, check one apartment number and wave through whoever is there at 21:30.
     */
    public function testTwoFlatsBackToBackStayTwoSessions(): void
    {
        $sessions = GuardService::group([
            $this->set($this->user(1), 1, 20),
            $this->set($this->user(2), 1, 21),
        ]);

        $this->assertCount(2, $sessions);
        $this->assertNotSame(
            GuardService::place($sessions[0]),
            GuardService::place($sessions[1]),
        );
    }

    public function testTheSameHourOnTwoPavilionsStaysTwoSessions(): void
    {
        $user = $this->user(1);
        $sessions = GuardService::group([
            $this->set($user, 1, 20),
            $this->set($user, 2, 21),
        ]);

        $this->assertCount(2, $sessions);
    }

    /**
     * The label always names the building. The ЖК is five buildings on one street with
     * repeating apartment numbers, so «кв. 45» names six flats — the same rule the
     * debtors' board and the rental noticeboard are pinned on.
     */
    public function testThePlaceLabelNamesTheBuilding(): void
    {
        $sessions = GuardService::group([$this->set($this->user(1), 1, 20)]);

        $this->assertStringContainsString('буд.', GuardService::place($sessions[0]));
    }

    /**
     * People arrive before the hour and pack up after it, and the pavilion photo is due
     * within the hour of the end. A board that says «вільно» at 19:52 or at 22:05 sends
     * the guard to move along exactly the people who booked it.
     */
    public function testASessionIsRunningEarlyAndDuringTheGrace(): void
    {
        $session = GuardService::group([$this->set($this->user(1), 1, 20)])[0];
        $at = static fn(string $time): \DateTimeImmutable
            => new \DateTimeImmutable('2026-09-07 ' . $time, new \DateTimeZone('Europe/Kyiv'));

        $this->assertFalse(GuardService::isRunning($session, $at('19:30')));
        $this->assertTrue(GuardService::isRunning($session, $at('19:52')), 'they arrive early');
        $this->assertTrue(GuardService::isRunning($session, $at('20:30')));
        $this->assertTrue(GuardService::isRunning($session, $at('21:10')), 'they are still packing up');
        $this->assertFalse(GuardService::isRunning($session, $at('21:30')));

        $this->assertFalse(GuardService::isOver($session, $at('21:10')));
        $this->assertTrue(GuardService::isOver($session, $at('21:30')));
    }

    #############
    # The screen itself
    #############

    /**
     * What the guard actually reads, in the order he reads it: the hour he is standing
     * in, then the rest of the evening. Pinned as text because that ordering *is* the
     * feature — a board that buries the running session under a full day's schedule is
     * one he stops opening.
     */
    public function testTheBoardLeadsWithWhatIsRunningNow(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 20:30', new \DateTimeZone('Europe/Kyiv'));

        $sessions = GuardService::group([
            $this->set($this->user(1), 1, 20),
            $this->set($this->user(2), 2, 22),
        ]);

        $board = $this->commandWith($sessions)->board($now);

        $this->assertStringContainsString('🔴 <b>Зараз</b>', $board);
        $this->assertStringContainsString('⏭ <b>Далі сьогодні</b>', $board);
        $this->assertLessThan(
            strpos($board, '⏭ <b>Далі сьогодні</b>'),
            strpos($board, '🔴 <b>Зараз</b>'),
            'the running session must come first',
        );
        $this->assertStringContainsString('20:00–21:00', $board);
        $this->assertStringContainsString('Перша альтанка', $board);
        $this->assertStringContainsString('Друга альтанка', $board);
        // The apartment is the whole point, and it always carries the building.
        $this->assertStringContainsString('буд. 19, кв. 41', $board);
    }

    /** An empty evening must say so plainly, not render an empty heading. */
    public function testAnEmptyEveningSaysTheePavilionsAreFree(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 20:30', new \DateTimeZone('Europe/Kyiv'));
        $board = $this->commandWith([])->board($now);

        $this->assertStringContainsString('вільні', $board);
        $this->assertStringNotContainsString('🔴', $board);
    }

    /** A session that ended an hour ago is off the board entirely. */
    public function testAFinishedSessionIsNotShown(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 22:30', new \DateTimeZone('Europe/Kyiv'));
        $sessions = GuardService::group([$this->set($this->user(1), 1, 20)]);

        $board = $this->commandWith($sessions)->board($now);

        $this->assertStringNotContainsString('20:00–21:00', $board);
        $this->assertStringContainsString('вільні', $board);
    }

    /** @param array<int, array<string, mixed>> $sessions */
    private function commandWith(array $sessions): \App\Telegram\Guard\Command\GuardCommand
    {
        $guard = $this->createMock(GuardService::class);
        $guard->method('sessionsOfDay')->willReturn($sessions);

        return new \App\Telegram\Guard\Command\GuardCommand(
            $guard,
            $this->createMock(\App\Service\TelegramUserService::class),
        );
    }
}
