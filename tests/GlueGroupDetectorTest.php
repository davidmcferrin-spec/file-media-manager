<?php

declare(strict_types=1);

use MediaManager\Services\GlueGroupDetector;
use PHPUnit\Framework\TestCase;

final class GlueGroupDetectorTest extends TestCase
{
    public function test_detects_base_and_numbered_parts(): void
    {
        $paths = [
            '/mnt/SHOW/Episode.mxf',
            '/mnt/SHOW/Episode_1.mxf',
            '/mnt/SHOW/Episode_2.mxf',
            '/mnt/SHOW/Other.mxf',
        ];
        $map = GlueGroupDetector::analyze($paths);

        $this->assertArrayHasKey('/mnt/SHOW/Episode.mxf', $map);
        $this->assertArrayHasKey('/mnt/SHOW/Episode_1.mxf', $map);
        $this->assertArrayHasKey('/mnt/SHOW/Episode_2.mxf', $map);
        $this->assertArrayNotHasKey('/mnt/SHOW/Other.mxf', $map);

        $this->assertSame(0, $map['/mnt/SHOW/Episode.mxf']['glue_part_index']);
        $this->assertSame(1, $map['/mnt/SHOW/Episode_1.mxf']['glue_part_index']);
        $this->assertSame(2, $map['/mnt/SHOW/Episode_2.mxf']['glue_part_index']);
        $this->assertSame(3, $map['/mnt/SHOW/Episode.mxf']['part_count']);
        $this->assertTrue(str_starts_with($map['/mnt/SHOW/Episode.mxf']['glue_group_key'], 'auto:'));
    }

    public function test_numbered_only_still_groups(): void
    {
        $map = GlueGroupDetector::analyze([
            '/a/Clip_1.mov',
            '/a/Clip_2.mov',
        ]);
        $this->assertCount(2, $map);
        $this->assertSame(
            $map['/a/Clip_1.mov']['glue_group_key'],
            $map['/a/Clip_2.mov']['glue_group_key']
        );
    }

    public function test_different_dirs_do_not_group(): void
    {
        $map = GlueGroupDetector::analyze([
            '/a/Show.mxf',
            '/b/Show_1.mxf',
        ]);
        $this->assertSame([], $map);
    }

    public function test_manual_group_orders_by_part(): void
    {
        $group = GlueGroupDetector::buildManualGroup([
            ['id' => 3, 'original_path' => '/x/A_2.mxf', 'original_filename' => 'A_2.mxf'],
            ['id' => 1, 'original_path' => '/x/A.mxf', 'original_filename' => 'A.mxf'],
            ['id' => 2, 'original_path' => '/x/A_1.mxf', 'original_filename' => 'A_1.mxf'],
        ]);
        $this->assertNotNull($group);
        $this->assertTrue(str_starts_with($group['glue_group_key'], 'manual:'));
        $this->assertSame([1, 2, 3], array_column($group['members'], 'id'));
        $this->assertSame([0, 1, 2], array_column($group['members'], 'glue_part_index'));
    }
}
