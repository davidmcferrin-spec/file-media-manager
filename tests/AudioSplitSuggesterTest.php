<?php

declare(strict_types=1);

use MediaManager\Services\AudioSplitSuggester;
use PHPUnit\Framework\TestCase;

final class AudioSplitSuggesterTest extends TestCase
{
    public function test_ignores_short_ad_dips_keeps_single_block(): void
    {
        // 2-minute quiet in the middle — below 30 min content gap
        $gaps = [
            ['start' => 1800.0, 'end' => 1920.0, 'duration' => 120.0],
        ];
        $result = (new AudioSplitSuggester())->suggestFromGaps($gaps, 7200.0);
        $this->assertSame(0, $result['content_gap_count']);
        $this->assertCount(1, $result['segments']);
        $this->assertSame(0.0, $result['segments'][0]['start']);
        $this->assertSame(7200.0, $result['segments'][0]['end']);
    }

    public function test_splits_on_long_quiet_gaps(): void
    {
        // Program A 0–3600, 40 min dead air, Program B 6000–10800
        $gaps = [
            ['start' => 3600.0, 'end' => 6000.0, 'duration' => 2400.0],
        ];
        $result = (new AudioSplitSuggester())->suggestFromGaps($gaps, 10800.0);
        $this->assertSame(1, $result['content_gap_count']);
        $this->assertCount(2, $result['segments']);
        $this->assertLessThan(3700.0, $result['segments'][0]['end']);
        $this->assertGreaterThanOrEqual(6000.0, $result['segments'][1]['start']);
        $this->assertSame('audio', $result['segments'][0]['confidence']);
    }

    public function test_drops_short_false_start_before_program(): void
    {
        // 2 min noise, 35 min quiet, then 2h program
        $gaps = [
            ['start' => 120.0, 'end' => 2220.0, 'duration' => 2100.0],
        ];
        $result = (new AudioSplitSuggester())->suggestFromGaps($gaps, 9420.0);
        $this->assertSame(1, $result['content_gap_count']);
        $this->assertCount(1, $result['segments']);
        $this->assertGreaterThanOrEqual(2220.0, $result['segments'][0]['start']);
    }

    public function test_defaults(): void
    {
        $this->assertSame(1800.0, AudioSplitSuggester::DEFAULT_CONTENT_GAP_SECONDS);
        $this->assertSame(540.0, AudioSplitSuggester::DEFAULT_MIN_PROGRAM_SECONDS);
        $this->assertSame(300.0, AudioSplitSuggester::DEFAULT_AD_IGNORE_SECONDS);
    }
}
