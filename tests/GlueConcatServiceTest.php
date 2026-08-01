<?php

declare(strict_types=1);

use MediaManager\Services\GlueConcatService;
use PHPUnit\Framework\TestCase;

final class GlueConcatServiceTest extends TestCase
{
    public function test_suggest_output_path_uses_base_stem(): void
    {
        $dir = sys_get_temp_dir() . '/glue_out_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $primary = $dir . '/Episode.mxf';
        touch($primary);

        $out = GlueConcatService::suggestOutputPath($primary);
        $this->assertSame($dir . '/Episode_GLUED.mxf', str_replace('\\', '/', $out));

        @unlink($primary);
        @rmdir($dir);
    }

    public function test_suggest_output_path_strips_part_suffix(): void
    {
        $dir = sys_get_temp_dir() . '/glue_out_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $primary = $dir . '/Episode_1.mxf';
        touch($primary);

        $out = GlueConcatService::suggestOutputPath($primary);
        $this->assertSame($dir . '/Episode_GLUED.mxf', str_replace('\\', '/', $out));

        @unlink($primary);
        @rmdir($dir);
    }

    public function test_suggest_output_path_avoids_collision(): void
    {
        $dir = sys_get_temp_dir() . '/glue_out_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $primary = $dir . '/Show.mov';
        touch($primary);
        touch($dir . '/Show_GLUED.mov');

        $out = GlueConcatService::suggestOutputPath($primary);
        $this->assertSame($dir . '/Show_GLUED_2.mov', str_replace('\\', '/', $out));

        @unlink($primary);
        @unlink($dir . '/Show_GLUED.mov');
        @rmdir($dir);
    }

    public function test_duration_looks_ok_within_tolerance(): void
    {
        $this->assertTrue(GlueConcatService::durationLooksOk(3600.0, 3601.5));
        $this->assertFalse(GlueConcatService::durationLooksOk(3600.0, 3500.0));
        $this->assertTrue(GlueConcatService::durationLooksOk(null, 100.0));
    }
}
