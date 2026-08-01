<?php

declare(strict_types=1);

use MediaManager\Services\SrtCaptionParser;
use PHPUnit\Framework\TestCase;

final class SrtCaptionParserTest extends TestCase
{
    public function test_parses_srt_cues(): void
    {
        $raw = <<<SRT
1
00:00:01,000 --> 00:00:03,500
Hello world

2
00:00:04,000 --> 00:00:05,000
Second line
SRT;
        $cues = SrtCaptionParser::parse($raw);
        $this->assertCount(2, $cues);
        $this->assertSame(1.0, $cues[0]['start']);
        $this->assertSame(3.5, $cues[0]['end']);
        $this->assertSame('Hello world', $cues[0]['text']);
        $this->assertSame('Second line', $cues[1]['text']);
    }

    public function test_parses_webvtt(): void
    {
        $raw = "WEBVTT\n\n00:01:00.000 --> 00:01:02.000\nHour mark\n";
        $cues = SrtCaptionParser::parse($raw);
        $this->assertCount(1, $cues);
        $this->assertSame(60.0, $cues[0]['start']);
        $this->assertSame('Hour mark', $cues[0]['text']);
    }
}
