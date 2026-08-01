<?php

declare(strict_types=1);

use MediaManager\Services\AudioSilenceDetector;
use PHPUnit\Framework\TestCase;

final class AudioSilenceDetectorTest extends TestCase
{
    public function test_parses_silence_start_end_duration(): void
    {
        $log = <<<LOG
[silencedetect @ 0x1] silence_start: 10.5
[silencedetect @ 0x1] silence_end: 40.5 | silence_duration: 30.0
[silencedetect @ 0x1] silence_start: 100
[silencedetect @ 0x1] silence_end: 1900.25 | silence_duration: 1800.25
LOG;
        $gaps = AudioSilenceDetector::parseSilencedetectLog($log);
        $this->assertCount(2, $gaps);
        $this->assertSame(10.5, $gaps[0]['start']);
        $this->assertSame(40.5, $gaps[0]['end']);
        $this->assertEqualsWithDelta(30.0, $gaps[0]['duration'], 0.01);
        $this->assertSame(100.0, $gaps[1]['start']);
        $this->assertGreaterThan(1800.0, $gaps[1]['duration']);
    }

    public function test_merges_overlapping_regions(): void
    {
        $log = <<<LOG
silence_start: 0
silence_end: 10 | silence_duration: 10
silence_start: 9.5
silence_end: 20 | silence_duration: 10.5
LOG;
        $gaps = AudioSilenceDetector::parseSilencedetectLog($log);
        $this->assertCount(1, $gaps);
        $this->assertSame(0.0, $gaps[0]['start']);
        $this->assertSame(20.0, $gaps[0]['end']);
    }
}
