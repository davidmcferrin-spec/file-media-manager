<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Parse WebVTT / SubRip caption files into timed cues.
 */
final class SrtCaptionParser
{
    /**
     * @return list<array{index: int, start: float, end: float, text: string}>
     */
    public static function parseFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        return self::parse($raw);
    }

    /**
     * @return list<array{index: int, start: float, end: float, text: string}>
     */
    public static function parse(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        // Drop WebVTT header line(s).
        $raw = preg_replace('/^WEBVTT[^\n]*\n+/i', '', $raw) ?? $raw;

        $blocks = preg_split('/\n{2,}/', trim($raw)) ?: [];
        $cues = [];
        $n = 0;

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            if ($lines === [] || $lines[0] === '') {
                continue;
            }

            // Optional numeric index line.
            if (preg_match('/^\d+$/', $lines[0]) === 1) {
                array_shift($lines);
            }
            if ($lines === []) {
                continue;
            }

            $timing = $lines[0];
            if (preg_match(
                '/^(\d{1,2}:\d{2}:\d{2}[,\.]\d{1,3})\s*-->\s*(\d{1,2}:\d{2}:\d{2}[,\.]\d{1,3})/',
                $timing,
                $m
            ) !== 1) {
                continue;
            }

            $start = self::timecodeToSeconds($m[1]);
            $end = self::timecodeToSeconds($m[2]);
            if ($end <= $start) {
                continue;
            }

            $textLines = array_slice($lines, 1);
            $text = trim(implode("\n", $textLines));
            // Strip simple HTML tags used in SRT/VTT.
            $text = trim(strip_tags($text));
            if ($text === '') {
                continue;
            }

            $n++;
            $cues[] = [
                'index' => $n,
                'start' => $start,
                'end'   => $end,
                'text'  => $text,
            ];
        }

        return $cues;
    }

    public static function timecodeToSeconds(string $tc): float
    {
        $tc = str_replace(',', '.', $tc);
        $parts = explode(':', $tc);
        if (count($parts) !== 3) {
            return 0.0;
        }
        $h = (float) $parts[0];
        $m = (float) $parts[1];
        $s = (float) $parts[2];

        return ($h * 3600.0) + ($m * 60.0) + $s;
    }

    public static function secondsToTimecode(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(fmod($seconds, 3600) / 60);
        $s = $seconds - ($h * 3600) - ($m * 60);
        $whole = (int) floor($s);
        $ms = (int) round(($s - $whole) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $whole, $ms);
    }
}
