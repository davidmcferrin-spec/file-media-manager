<?php

declare(strict_types=1);

namespace MediaManager\Services;

final class FFprobeService
{
    private string $bin;

    public function __construct(?string $bin = null)
    {
        $this->bin = $bin ?? (string) env('FFPROBE_BIN', '/usr/bin/ffprobe');
    }

    public function isAvailable(): bool
    {
        if (!is_executable($this->bin)) {
            return false;
        }

        $output = [];
        $code   = 0;
        exec(escapeshellcmd($this->bin) . ' -version 2>&1', $output, $code);

        return $code === 0;
    }

    /**
     * @return array{summary: array<string, mixed>, raw: array<string, mixed>}|null
     */
    public function probeRaw(string $filePath): ?array
    {
        if (!$this->isAvailable() || !is_readable($filePath)) {
            return null;
        }

        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>&1',
            escapeshellcmd($this->bin),
            escapeshellarg($filePath)
        );

        $json = shell_exec($cmd);
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'summary' => $this->normalize($data),
            'raw'     => $data,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function probe(string $filePath): ?array
    {
        $result = $this->probeRaw($filePath);

        return $result !== null ? $result['summary'] : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $format   = is_array($data['format'] ?? null) ? $data['format'] : [];
        $streams  = is_array($data['streams'] ?? null) ? $data['streams'] : [];
        $video    = null;
        $audio    = null;

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $codecType = (string) ($stream['codec_type'] ?? '');
            if ($codecType === 'video' && $video === null) {
                $video = $stream;
            }
            if ($codecType === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        $duration = isset($format['duration']) ? (float) $format['duration'] : null;
        $creation = null;
        if (isset($format['tags']) && is_array($format['tags'])) {
            $creation = $format['tags']['creation_time'] ?? $format['tags']['DATE'] ?? null;
        }

        $resolution = null;
        $framerate  = null;
        if ($video !== null) {
            $w = (int) ($video['width'] ?? 0);
            $h = (int) ($video['height'] ?? 0);
            if ($w > 0 && $h > 0) {
                $resolution = $w . 'x' . $h;
            }
            if (isset($video['avg_frame_rate']) && is_string($video['avg_frame_rate'])) {
                $framerate = $this->parseFrameRate($video['avg_frame_rate']);
            }
        }

        $container = null;
        if (isset($format['format_name']) && is_string($format['format_name'])) {
            $container = explode(',', $format['format_name'])[0] ?? null;
        }

        return [
            'duration'       => $duration,
            'creation_time'  => is_string($creation) ? $creation : null,
            'filesize_bytes' => isset($format['size']) ? (int) $format['size'] : null,
            'container'      => $container,
            'codec_video'    => isset($video['codec_name']) ? (string) $video['codec_name'] : null,
            'codec_audio'    => isset($audio['codec_name']) ? (string) $audio['codec_name'] : null,
            'resolution'     => $resolution,
            'framerate'      => $framerate,
        ];
    }

    private function parseFrameRate(string $rate): ?string
    {
        if (str_contains($rate, '/')) {
            [$num, $den] = array_map('floatval', explode('/', $rate, 2));
            if ($den > 0) {
                return (string) round($num / $den, 3);
            }
        }

        return $rate;
    }
}
