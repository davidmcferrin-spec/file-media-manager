<?php

declare(strict_types=1);

namespace MediaManager\Services;

use RuntimeException;

/**
 * Run ffmpeg silencedetect and parse silence regions from stderr.
 */
final class AudioSilenceDetector
{
    private string $ffmpegBin;

    public function __construct(
        ?string $ffmpegBin = null,
        private readonly float $noiseDb = -35.0,
        private readonly float $minSilenceSeconds = 2.0,
    ) {
        $this->ffmpegBin = $ffmpegBin ?? (string) env('FFMPEG_BIN', '/usr/bin/ffmpeg');
    }

    /**
     * @return list<array{start: float, end: float, duration: float}>
     */
    public function detect(string $mediaPath): array
    {
        if (!is_readable($mediaPath)) {
            throw new RuntimeException('Media file is not readable: ' . $mediaPath);
        }
        if (!is_executable($this->ffmpegBin) && !is_file($this->ffmpegBin)) {
            throw new RuntimeException('FFmpeg is not available at ' . $this->ffmpegBin);
        }

        $noise = $this->formatNoiseDb($this->noiseDb);
        $duration = max(0.5, $this->minSilenceSeconds);
        $filter = sprintf('silencedetect=noise=%sdB:d=%s', $noise, self::formatFloat($duration));

        $cmd = sprintf(
            '%s -hide_banner -nostats -i %s -af %s -f null - 2>&1',
            escapeshellcmd($this->ffmpegBin),
            escapeshellarg($mediaPath),
            escapeshellarg($filter)
        );

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $log = implode("\n", $output);

        // silencedetect writes to stderr; ffmpeg may still exit 0.
        $gaps = self::parseSilencedetectLog($log);
        if ($gaps === [] && $code !== 0 && !str_contains($log, 'silence_start')) {
            $tail = trim(substr($log, -800));
            throw new RuntimeException(
                'FFmpeg silencedetect failed (exit ' . $code . ')'
                . ($tail !== '' ? ': ' . $tail : '')
            );
        }

        return $gaps;
    }

    /**
     * @return list<array{start: float, end: float, duration: float}>
     */
    public static function parseSilencedetectLog(string $log): array
    {
        $starts = [];
        $gaps = [];

        foreach (preg_split('/\r\n|\n|\r/', $log) ?: [] as $line) {
            if (preg_match('/silence_start:\s*([0-9.]+)/', $line, $m) === 1) {
                $starts[] = (float) $m[1];
                continue;
            }
            if (preg_match('/silence_end:\s*([0-9.]+)\s*\|\s*silence_duration:\s*([0-9.]+)/', $line, $m) === 1) {
                $end = (float) $m[1];
                $dur = (float) $m[2];
                $start = $starts !== [] ? array_pop($starts) : max(0.0, $end - $dur);
                if ($end > $start) {
                    $gaps[] = [
                        'start'    => round($start, 3),
                        'end'      => round($end, 3),
                        'duration' => round($end - $start, 3),
                    ];
                }
                continue;
            }
            // Some builds omit silence_duration on the same line.
            if (preg_match('/silence_end:\s*([0-9.]+)/', $line, $m) === 1) {
                $end = (float) $m[1];
                $start = $starts !== [] ? array_pop($starts) : null;
                if ($start !== null && $end > $start) {
                    $gaps[] = [
                        'start'    => round($start, 3),
                        'end'      => round($end, 3),
                        'duration' => round($end - $start, 3),
                    ];
                }
            }
        }

        usort($gaps, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return self::mergeOverlapping($gaps);
    }

    /**
     * @param list<array{start: float, end: float, duration: float}> $gaps
     * @return list<array{start: float, end: float, duration: float}>
     */
    private static function mergeOverlapping(array $gaps): array
    {
        if ($gaps === []) {
            return [];
        }

        $out = [];
        $cur = $gaps[0];
        for ($i = 1, $n = count($gaps); $i < $n; $i++) {
            $next = $gaps[$i];
            if ($next['start'] <= $cur['end'] + 0.25) {
                $cur['end'] = max($cur['end'], $next['end']);
                $cur['duration'] = round($cur['end'] - $cur['start'], 3);
            } else {
                $out[] = $cur;
                $cur = $next;
            }
        }
        $out[] = $cur;

        return $out;
    }

    private function formatNoiseDb(float $db): string
    {
        // ffmpeg wants e.g. -35 or -35.0
        return self::formatFloat($db);
    }

    private static function formatFloat(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
