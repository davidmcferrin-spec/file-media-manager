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
        $hasCaptions = false;
        $captionStreamIndex = null;

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
            if (self::streamLooksLikeCaptions($stream)) {
                $hasCaptions = true;
                if ($captionStreamIndex === null && isset($stream['index'])) {
                    $captionStreamIndex = (int) $stream['index'];
                }
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
            'duration'              => $duration,
            'creation_time'         => is_string($creation) ? $creation : null,
            'filesize_bytes'        => isset($format['size']) ? (int) $format['size'] : null,
            'container'             => $container,
            'codec_video'           => isset($video['codec_name']) ? (string) $video['codec_name'] : null,
            'codec_audio'           => isset($audio['codec_name']) ? (string) $audio['codec_name'] : null,
            'resolution'            => $resolution,
            'framerate'             => $framerate,
            'has_captions'          => $hasCaptions,
            'caption_stream_index'  => $captionStreamIndex,
        ];
    }

    /** @param array<string, mixed> $stream */
    public static function streamLooksLikeCaptions(array $stream): bool
    {
        $codecType = strtolower((string) ($stream['codec_type'] ?? ''));
        $codecName = strtolower((string) ($stream['codec_name'] ?? ''));
        $codecTag = strtolower((string) ($stream['codec_tag_string'] ?? ''));

        if ($codecType === 'subtitle') {
            return true;
        }

        $disposition = is_array($stream['disposition'] ?? null) ? $stream['disposition'] : [];
        if (!empty($disposition['captions']) || !empty($disposition['hearing_impaired'])) {
            return true;
        }

        foreach (['eia_608', 'eia_708', 'cea608', 'cea708', 'dvb_teletext', 'hdmv_pgs', 'dvd_subtitle', 'mov_text', 'subrip', 'ass', 'webvtt'] as $needle) {
            if (str_contains($codecName, $needle) || str_contains($codecTag, $needle)) {
                return true;
            }
        }

        $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];
        foreach (['title', 'handler_name', 'language'] as $key) {
            $val = strtolower((string) ($tags[$key] ?? ''));
            if ($val !== '' && (str_contains($val, 'caption') || str_contains($val, 'subtitle') || $val === 'cc')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Path of an existing caption sidecar next to a media file (.srt preferred, then .vtt).
     */
    public static function adjacentCaptionSidecar(string $mediaPath): ?string
    {
        $dir = dirname($mediaPath);
        $stem = pathinfo($mediaPath, PATHINFO_FILENAME);
        foreach (['srt', 'vtt'] as $ext) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $stem . '.' . $ext;
            if (is_readable($candidate)) {
                return str_replace('\\', '/', $candidate);
            }
        }

        return null;
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
