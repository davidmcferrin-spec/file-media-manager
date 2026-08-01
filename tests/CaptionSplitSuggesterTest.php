<?php

declare(strict_types=1);

use MediaManager\Services\CaptionSplitSuggester;
use PHPUnit\Framework\TestCase;

final class CaptionSplitSuggesterTest extends TestCase
{
    public function test_ignores_short_commercial_gaps(): void
    {
        $cues = [
            ['start' => 0.0, 'end' => 10.0, 'text' => 'A'],
            // 2-minute gap (commercial) — must not split
            ['start' => 130.0, 'end' => 140.0, 'text' => 'B'],
            ['start' => 200.0, 'end' => 210.0, 'text' => 'C'],
        ];
        $result = (new CaptionSplitSuggester())->suggest($cues, 220.0);
        $this->assertSame(0, $result['gap_count']);
        $this->assertCount(1, $result['segments']);
    }

    public function test_splits_on_five_minute_gaps(): void
    {
        $cues = [
            ['start' => 0.0, 'end' => 60.0, 'text' => 'Block1'],
            // 6-minute silence
            ['start' => 420.0, 'end' => 480.0, 'text' => 'Block2'],
            ['start' => 500.0, 'end' => 520.0, 'text' => 'Block2b'],
        ];
        $result = (new CaptionSplitSuggester())->suggest($cues, 540.0);
        $this->assertSame(1, $result['gap_count']);
        $this->assertGreaterThanOrEqual(2, count($result['segments']));
        $this->assertLessThan(100.0, $result['segments'][0]['end']);
        $this->assertGreaterThanOrEqual(420.0, $result['segments'][1]['start']);
    }

    public function test_min_gap_constant_is_five_minutes(): void
    {
        $this->assertSame(300.0, CaptionSplitSuggester::MIN_GAP_SECONDS);
    }
}
