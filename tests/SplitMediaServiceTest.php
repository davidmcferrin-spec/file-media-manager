<?php

declare(strict_types=1);

use MediaManager\Services\SplitMediaService;
use PHPUnit\Framework\TestCase;

final class SplitMediaServiceTest extends TestCase
{
    private SplitMediaService $svc;

    protected function setUp(): void
    {
        $this->svc = new SplitMediaService();
    }

    public function test_mp4_h264_is_fast_path(): void
    {
        $mode = $this->svc->playMode([
            'original_filename' => 'SHOW_20240101_1200.mp4',
            'container'         => 'mov,mp4,m4a,3gp,3g2,mj2',
            'codec_video'       => 'h264',
        ]);
        $this->assertSame(SplitMediaService::MODE_FAST, $mode);
    }

    public function test_ts_mpeg2_is_proxy_path(): void
    {
        $mode = $this->svc->playMode([
            'original_filename' => 'capture.ts',
            'container'         => 'mpegts',
            'codec_video'       => 'mpeg2video',
        ]);
        $this->assertSame(SplitMediaService::MODE_PROXY, $mode);
    }

    public function test_mxf_dnxhd_is_proxy_path(): void
    {
        $mode = $this->svc->playMode([
            'original_filename' => 'clip.mxf',
            'container'         => 'mxf',
            'codec_video'       => 'dnxhd',
        ]);
        $this->assertSame(SplitMediaService::MODE_PROXY, $mode);
    }

    public function test_wmv_is_unsupported(): void
    {
        $mode = $this->svc->playMode([
            'original_filename' => 'legacy.wmv',
            'container'         => 'asf',
            'codec_video'       => 'wmv3',
        ]);
        $this->assertSame(SplitMediaService::MODE_UNSUPPORTED, $mode);
    }

    public function test_frame_bucket_half_second(): void
    {
        $this->assertSame(0, $this->svc->frameBucket(0.0));
        $this->assertSame(1, $this->svc->frameBucket(0.4));
        $this->assertSame(2, $this->svc->frameBucket(1.0));
        $this->assertSame(7, $this->svc->frameBucket(3.4));
    }
}
