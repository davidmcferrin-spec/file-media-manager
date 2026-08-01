<?php

declare(strict_types=1);

use MediaManager\Services\AudioLevelMapService;
use PHPUnit\Framework\TestCase;

final class AudioLevelMapServiceTest extends TestCase
{
    public function test_silence_gaps_mark_quiet_buckets(): void
    {
        $gaps = [
            ['start' => 10.0, 'end' => 20.0],
        ];
        $levels = AudioLevelMapService::levelsFromSilenceGaps($gaps, 30.0, 2.0);
        $this->assertCount(15, $levels);
        $this->assertSame(2, $levels[0]);
        $this->assertSame(0, $levels[5]); // 10–12s
        $this->assertSame(0, $levels[9]); // 18–20s
        $this->assertSame(2, $levels[10]); // 20–22s
    }

    public function test_quantize_rms_uses_noise_and_percentiles(): void
    {
        $rms = [-50.0, -40.0, -30.0, -25.0, -20.0, -10.0, null];
        $levels = AudioLevelMapService::quantizeRms($rms, -35.0);
        $this->assertSame(0, $levels[0]); // below noise
        $this->assertSame(0, $levels[6]); // null
        $this->assertContains($levels[2], [1, 2, 3]);
        $this->assertSame(3, $levels[5]); // hottest active
    }

    public function test_levels_to_blocks_run_length(): void
    {
        $blocks = AudioLevelMapService::levelsToBlocks([0, 0, 2, 2, 2, 3], 2.0, 12.0);
        $this->assertCount(3, $blocks);
        $this->assertSame(0, $blocks[0]['level']);
        $this->assertSame(0.0, $blocks[0]['start']);
        $this->assertSame(4.0, $blocks[0]['end']);
        $this->assertSame(2, $blocks[1]['level']);
        $this->assertSame(3, $blocks[2]['level']);
    }

    public function test_parse_rms_metadata(): void
    {
        $raw = <<<TXT
frame:0 pts:0 pts_time:0
lavfi.astats.Overall.RMS_level=-22.5
frame:1 pts:16000 pts_time:2
lavfi.astats.Overall.RMS_level=-inf
frame:2 pts:32000 pts_time:4
lavfi.astats.Overall.RMS_level=-18.1
TXT;
        $rms = AudioLevelMapService::parseRmsMetadata($raw);
        $this->assertCount(3, $rms);
        $this->assertEqualsWithDelta(-22.5, $rms[0], 0.01);
        $this->assertNull($rms[1]);
        $this->assertEqualsWithDelta(-18.1, $rms[2], 0.01);
    }
}
