<?php

declare(strict_types=1);

use MediaManager\Services\ScheduleLookupService;
use MediaManager\Services\ScheduleSplitSuggester;
use PHPUnit\Framework\TestCase;

final class ScheduleSplitSuggesterTest extends TestCase
{
    private function suggester(int $thresholdSeconds = 7200): ScheduleSplitSuggester
    {
        $lookup = $this->createStub(ScheduleLookupService::class);
        $lookup->method('match')->willReturn(null);

        return new ScheduleSplitSuggester($lookup, $thresholdSeconds);
    }

    public function test_under_threshold_crossing_hour_does_not_flag(): void
    {
        // 1h22m30s starting 14:50 — spans two clock hours, under 2h threshold
        $result = $this->suggester()->suggest('20250115', '1450', 4950.0);

        $this->assertFalse($result['needs_split']);
        $this->assertSame([], $result['segments']);
    }

    public function test_thirty_minutes_crossing_hour_does_not_flag(): void
    {
        $result = $this->suggester()->suggest('20250115', '1450', 1800.0);

        $this->assertFalse($result['needs_split']);
        $this->assertSame([], $result['segments']);
    }

    public function test_at_threshold_with_multiple_hourly_blocks_flags(): void
    {
        // Exactly 2h from 14:00 — two hourly blocks
        $result = $this->suggester()->suggest('20250115', '1400', 7200.0);

        $this->assertTrue($result['needs_split']);
        $this->assertGreaterThanOrEqual(2, count($result['segments']));
        $this->assertNotSame('', $result['notes']);
    }
}
