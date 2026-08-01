<?php

declare(strict_types=1);

use MediaManager\Services\SplitExportPolicy;
use PHPUnit\Framework\TestCase;

final class SplitExportPolicyTest extends TestCase
{
    public function test_pads_five_minutes_when_room_exists(): void
    {
        $range = SplitExportPolicy::exportRange(600.0, 3600.0, 7200.0);

        $this->assertSame(300.0, $range['export_start']);
        $this->assertSame(3900.0, $range['export_end']);
        $this->assertSame(300.0, $range['pad_before']);
        $this->assertSame(300.0, $range['pad_after']);
    }

    public function test_clamps_pad_at_file_edges(): void
    {
        $range = SplitExportPolicy::exportRange(60.0, 3500.0, 3600.0);

        $this->assertSame(0.0, $range['export_start']);
        $this->assertSame(3600.0, $range['export_end']);
        $this->assertSame(60.0, $range['pad_before']);
        $this->assertSame(100.0, $range['pad_after']);
    }

    public function test_handle_is_five_minutes(): void
    {
        $this->assertSame(300, SplitExportPolicy::HANDLE_SECONDS);
        $this->assertSame(5, SplitExportPolicy::handleMinutes());
    }
}
