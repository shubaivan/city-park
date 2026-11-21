<?php

namespace App\Tests\Service;

use App\Service\GuardCalendar;
use PHPUnit\Framework\TestCase;

/** The month calendar on /admin/scans (21.11.2026): its weeks, its counts, its months. */
class GuardCalendarRulesTest extends TestCase
{
    public function testTheMonthIsWholeWeeksFromMonday(): void
    {
        $weeks = GuardCalendar::grid(new \DateTime('2026-11-01'), [], new \DateTime('2026-11-21 10:00'));

        // 1 Nov 2026 is a Sunday: the first week is six blanks and the 1st; 30 Nov is a Monday.
        $this->assertSame('2026-10-26', $weeks[0]['monday']);
        $this->assertSame([null, null, null, null, null, null], array_slice($weeks[0]['days'], 0, 6));
        $this->assertSame('2026-11-01', $weeks[0]['days'][6]['date']);
        $this->assertSame('2026-11-30', end($weeks)['monday']);
        $this->assertCount(6, $weeks);
        foreach ($weeks as $w) {
            $this->assertCount(7, $w['days']);
        }
    }

    public function testCountsAndHowBusyADayLooks(): void
    {
        $weeks = GuardCalendar::grid(new \DateTime('2026-11-01'), [
            '2026-11-16' => ['in' => 10, 'out' => 6, 'scans' => 4],
            '2026-11-17' => ['in' => 0, 'out' => 0, 'scans' => 1],
            '2026-11-18' => ['in' => 5, 'out' => 5, 'scans' => 0],
        ], new \DateTime('2026-11-18 10:00'));
        $days = [];
        foreach ($weeks as $w) {
            foreach (array_filter($w['days']) as $d) {
                $days[$d['date']] = $d;
            }
        }

        $this->assertSame(20, $days['2026-11-16']['total']);
        $this->assertSame(4, $days['2026-11-16']['level'], 'the busiest day is the darkest');
        $this->assertSame(1, $days['2026-11-17']['level'], 'one line is still visible');
        $this->assertSame(2, $days['2026-11-18']['level']);
        $this->assertSame(0, $days['2026-11-15']['level']);
        $this->assertTrue($days['2026-11-18']['today']);
        $this->assertTrue($days['2026-11-19']['future']);
        $this->assertFalse($days['2026-11-18']['future']);
    }

    public function testTheMonthParameterIsReadSafely(): void
    {
        $now = new \DateTime('2026-11-21 10:00');
        $default = new \DateTime('2026-11-16');

        $this->assertSame('2026-10-01', GuardCalendar::parseMonth('2026-10', $default, $now)->format('Y-m-d'));
        $this->assertSame('2026-11-01', GuardCalendar::parseMonth(null, $default, $now)->format('Y-m-d'));
        $this->assertSame('2026-11-01', GuardCalendar::parseMonth('2026-13', $default, $now)->format('Y-m-d'));
        $this->assertSame('2026-11-01', GuardCalendar::parseMonth('garbage', $default, $now)->format('Y-m-d'));
        $this->assertSame('2026-11-01', GuardCalendar::parseMonth('2026-12', $default, $now)->format('Y-m-d'), 'no future months');
        $this->assertSame('Листопад 2026', GuardCalendar::monthLabel(new \DateTime('2026-11-01')));
    }
}
