<?php

declare(strict_types=1);

use MediaManager\Services\ContinuityCheckService;
use PHPUnit\Framework\TestCase;

final class ContinuityCheckServiceTest extends TestCase
{
    public function test_agree_never_raises_above_rule_score(): void
    {
        $merged = ContinuityCheckService::mergeVerdict('MEDIUM', [
            'agree'      => true,
            'confidence' => 'HIGH',
            'show_id'    => 3,
        ]);
        $this->assertSame('MEDIUM', $merged['confidence']);
        $this->assertNull($merged['adopt_show_id']);
        $this->assertSame('continuity:confirmed', $merged['signal']);
    }

    public function test_disagree_drops_high_to_low(): void
    {
        $merged = ContinuityCheckService::mergeVerdict('HIGH', [
            'agree'      => false,
            'confidence' => 'MEDIUM',
            'show_id'    => 9,
        ]);
        $this->assertSame('LOW', $merged['confidence']);
        $this->assertSame(9, $merged['adopt_show_id']);
        $this->assertSame('continuity:conflict', $merged['signal']);
    }

    public function test_uncertain_blunts_high(): void
    {
        $merged = ContinuityCheckService::mergeVerdict('HIGH', [
            'confidence' => 'HIGH',
        ]);
        $this->assertSame('MEDIUM', $merged['confidence']);
        $this->assertSame('continuity:review', $merged['signal']);
    }
}
