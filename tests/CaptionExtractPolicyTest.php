<?php

declare(strict_types=1);

use MediaManager\Services\SplitPrepService;
use PHPUnit\Framework\TestCase;

final class CaptionExtractPolicyTest extends TestCase
{
    public function test_usable_captions_requires_readable_srt_with_cues(): void
    {
        $svc = new SplitPrepService();
        $this->assertFalse($svc->hasUsableCaptions(['srt_path' => '', 'has_captions' => true]));
        $this->assertFalse($svc->hasUsableCaptions(['srt_path' => null]));
        $this->assertFalse($svc->hasUsableCaptions([
            'srt_path' => sys_get_temp_dir() . '/mm-missing-' . uniqid('', true) . '.srt',
        ]));
    }

    public function test_usable_captions_true_for_valid_sidecar(): void
    {
        $path = sys_get_temp_dir() . '/mm-cap-' . uniqid('', true) . '.srt';
        file_put_contents(
            $path,
            "1\n00:00:00,000 --> 00:00:01,000\nHello\n"
        );
        try {
            $svc = new SplitPrepService();
            $this->assertTrue($svc->hasUsableCaptions(['srt_path' => $path]));
        } finally {
            @unlink($path);
        }
    }
}
