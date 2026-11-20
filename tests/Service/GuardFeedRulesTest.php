<?php

namespace App\Tests\Service;

use App\Entity\GateEvent;
use App\Entity\QrScan;
use App\EventSubscriber\GuardPanelScopeSubscriber;
use App\Service\GuardFeed;
use PHPUnit\Framework\TestCase;

/**
 * The guard post's feed (20.11.2026): what is one line, in what order, which week.
 */
class GuardFeedRulesTest extends TestCase
{
    private int $id = 100;

    public function testAScanThatGotItsPressIsDrawnOnceAsThePress(): void
    {
        $scan = $this->scan('2026-11-20 10:00');
        $press = $this->event(GateEvent::DIRECTION_IN, '2026-11-20 10:01', $scan);
        $other = $this->scan('2026-11-20 10:05');

        $items = GuardFeed::merge([$other, $scan], [$press]);

        $this->assertSame(['s' . $other->getId(), 'e' . $press->getId()], array_column($items, 'key'));
        $this->assertSame('s' . $scan->getId(), $items[1]['replaces'], 'a press arriving later swaps out its scan on the screen');
        $this->assertSame([GuardFeed::TYPE_SCAN, GuardFeed::TYPE_IN], array_column($items, 'type'));
    }

    public function testNewestFirstAndGroupedByDay(): void
    {
        $items = GuardFeed::merge(
            [$this->scan('2026-11-19 23:50'), $this->scan('2026-11-20 08:00')],
            [$this->event(GateEvent::DIRECTION_OUT, '2026-11-20 09:00'), $this->event(GateEvent::DIRECTION_IN, '2026-11-19 07:00')],
        );
        $days = GuardFeed::byDay($items);

        $this->assertSame(['2026-11-20', '2026-11-19'], array_column($days, 'date'));
        $this->assertSame("П'ятниця, 20.11", $days[0]['label']);
        $this->assertSame('Четвер, 19.11', $days[1]['label']);
        $this->assertSame([GuardFeed::TYPE_OUT, GuardFeed::TYPE_SCAN], array_column($days[0]['items'], 'type'));
        $this->assertSame([0, 1, 1], [$days[0]['in'], $days[0]['out'], $days[0]['scans']]);
        $this->assertSame([1, 0, 1], [$days[1]['in'], $days[1]['out'], $days[1]['scans']]);
    }

    public function testTheWeekStartsOnMonday(): void
    {
        $this->assertSame('2026-11-16 00:00', GuardFeed::weekStart(new \DateTime('2026-11-20 15:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-16 00:00', GuardFeed::weekStart(new \DateTime('2026-11-16 00:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-16 00:00', GuardFeed::weekStart(new \DateTime('2026-11-22 23:59'))->format('Y-m-d H:i'));
        $this->assertSame('16.11 – 22.11.2026', GuardFeed::weekLabel(new \DateTime('2026-11-16')));
    }

    public function testTheWeekParameterIsReadSafely(): void
    {
        $now = new \DateTime('2026-11-20 15:00');

        $this->assertSame('2026-11-09', GuardFeed::parseWeek('2026-11-11', $now)->format('Y-m-d'), 'any day means its week');
        $this->assertSame('2026-11-16', GuardFeed::parseWeek(null, $now)->format('Y-m-d'));
        $this->assertSame('2026-11-16', GuardFeed::parseWeek('garbage', $now)->format('Y-m-d'));
        $this->assertSame('2026-11-16', GuardFeed::parseWeek('2026-02-31', $now)->format('Y-m-d'));
        $this->assertSame('2026-11-16', GuardFeed::parseWeek('2027-01-04', $now)->format('Y-m-d'), 'no future weeks');
    }

    public function testOnlyACodeThatDidNotCheckOutIsFlagged(): void
    {
        $this->assertNull(GuardFeed::problem(QrScan::RESULT_OK));
        $this->assertNull(GuardFeed::problem(QrScan::RESULT_NO_BOOKING), 'the альтанка is not the guard\'s business');
        $this->assertNotNull(GuardFeed::problem(QrScan::RESULT_NOT_ACTIVE));
        $this->assertNotNull(GuardFeed::problem(QrScan::RESULT_REVOKED));
        $this->assertNotNull(GuardFeed::problem(QrScan::RESULT_UNKNOWN));
        $this->assertNotNull(GuardFeed::problem('something_new'));
    }

    public function testTheCursorKeepsTheNewestIds(): void
    {
        $this->assertSame(['scan' => 7, 'event' => 3], GuardFeed::cursor([], [], 7, 3));

        $s = $this->scan('2026-11-20 10:00');
        $e = $this->event(GateEvent::DIRECTION_IN, '2026-11-20 10:00');
        $this->assertSame(['scan' => $s->getId(), 'event' => $e->getId()], GuardFeed::cursor([$s], [$e], 1, 1));
    }

    /** The poll is open to the guard's login, or its page goes quiet without a word. */
    public function testTheGuardMayPoll(): void
    {
        $this->assertContains('app_admin_scans_feed', GuardPanelScopeSubscriber::ALLOWED_ROUTES);
        $security = (string)file_get_contents(__DIR__ . '/../../config/packages/security.yaml');
        $this->assertStringContainsString('- { path: ^/admin/scans/feed$, methods: [GET], roles: [ROLE_ADMIN, ROLE_COMPLAINTS, ROLE_GUARD] }', $security);
    }

    private function scan(string $at): QrScan
    {
        $scan = (new QrScan())->setSubjectLabel('буд. 19, кв. 12')->setScannedByLabel('Охорона');

        return $this->stamp($scan, $at);
    }

    private function event(string $direction, string $at, ?QrScan $scan = null): GateEvent
    {
        $event = (new GateEvent())->setDirection($direction)->setPersonLabel('Олена')->setPlaceLabel('буд. 19, кв. 85')->setQrScan($scan);

        return $this->stamp($event, $at);
    }

    /** @template T of object @param T $entity @return T */
    private function stamp(object $entity, string $at): object
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $this->id++);
        (new \ReflectionProperty($entity::class, 'created_at'))->setValue($entity, new \DateTime($at));

        return $entity;
    }
}
