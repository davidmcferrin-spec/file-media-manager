<?php

declare(strict_types=1);

use MediaManager\Services\ScheduleTimeParser;
use PHPUnit\Framework\TestCase;

final class ScheduleTimeParserTest extends TestCase
{
    public function test_parse_time_slot(): void
    {
        $slot = ScheduleTimeParser::parseTimeSlot('8:00 PM – 11:00 PM');
        $this->assertNotNull($slot);
        $this->assertSame(20 * 60, $slot['start']);
        $this->assertSame(23 * 60, $slot['end']);
    }

    public function test_eleven_to_midnight_not_overnight_spill(): void
    {
        $slot = ScheduleTimeParser::parseTimeSlot('11:00 PM – 12:00 AM');
        $this->assertNotNull($slot);
        $this->assertFalse(ScheduleTimeParser::isOvernightSpill($slot['start'], $slot['end']));
    }

    public function test_overnight_spill_detected(): void
    {
        $this->assertTrue(ScheduleTimeParser::isOvernightSpill(23 * 60, 2 * 60));
    }

    public function test_expand_to_three_hourly_blocks(): void
    {
        $blocks = ScheduleTimeParser::expandToHourlyBlocks(20 * 60, 23 * 60);
        $this->assertCount(3, $blocks);
    }

    public function test_parse_weekdays(): void
    {
        $mask = ScheduleTimeParser::parseDays('Mon–Fri');
        $this->assertSame(31, $mask);
    }

    public function test_replay_notes(): void
    {
        $this->assertTrue(ScheduleTimeParser::isReplayNotes('Re-aired at 1 AM and 4 AM ET'));
    }
}
