<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;

/**
 * Extract embedded captions to an .srt sidecar beside the media file.
 */
final class CaptionExtractService
{
    private string $ffmpegBin;
    private int $timeoutSeconds;

    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly FFprobeService $ffprobe = new FFprobeService(),
        ?string $ffmpegBin = null,
    ) {
        $this->ffmpegBin = $ffmpegBin ?? (string) env('FFMPEG_BIN', '/usr/bin/ffmpeg');
        $this->timeoutSeconds = max(0, (int) env('CAPTION_EXTRACT_TIMEOUT_SECONDS', 900));
    }

    public function isAvailable(): bool
    {
        if (!is_executable($this->ffmpegBin)) {
            return false;
        }
        $output = [];
        $code = 0;
        exec(escapeshellcmd($this->ffmpegBin) . ' -version 2>&1', $output, $code);

        return $code === 0;
    }

    /**
     * Probe only — update Catalog CC badge flags without FFmpeg extract.
     *
     * @return array{
     *   ok: bool,
     *   skip: bool,
     *   srt_path: ?string,
     *   message: string,
     *   ffmpeg_tail: string
     * }
     */
    public function probeForFile(int $fileId): array
    {
        $fail = static fn (string $msg, bool $skip = false): array => [
            'ok'          => false,
            'skip'        => $skip,
            'srt_path'    => null,
            'message'     => $msg,
            'ffmpeg_tail' => '',
        ];

        $file = $this->files->findById($fileId);
        if ($file === null) {
            return $fail('File not found.');
        }

        $source = FileRepository::mediaSourcePath($file);
        if ($source === '' || !is_readable($source)) {
            return $fail('Media file is not readable: ' . $source);
        }

        $existing = FFprobeService::adjacentCaptionSidecar($source);
        if ($existing !== null && str_ends_with(strtolower($existing), '.srt')) {
            $this->files->recordSrtSidecar($fileId, $existing, true, null);

            return [
                'ok'          => true,
                'skip'        => false,
                'srt_path'    => $existing,
                'message'     => 'Existing SRT sidecar linked.',
                'ffmpeg_tail' => '',
            ];
        }

        $probe = $this->ffprobe->probe($source);
        if ($probe === null) {
            return $fail('FFprobe failed — captions_probed left unchanged.');
        }

        $streamIndex = isset($probe['caption_stream_index'])
            ? (int) $probe['caption_stream_index']
            : null;
        $hasCaptions = !empty($probe['has_captions']) || $streamIndex !== null;
        $this->files->updateCaptionFlags($fileId, $hasCaptions, $streamIndex);

        if ($hasCaptions) {
            return [
                'ok'          => true,
                'skip'        => false,
                'srt_path'    => null,
                'message'     => 'Captions detected (stream '
                    . ($streamIndex !== null ? (string) $streamIndex : '?') . ').',
                'ffmpeg_tail' => '',
            ];
        }

        return $fail(
            'No caption/subtitle stream detected. (CEA-608 in MXF may need a separate extractor.)',
            true
        );
    }

    /**
     * @return array{
     *   ok: bool,
     *   skip: bool,
     *   srt_path: ?string,
     *   message: string,
     *   ffmpeg_tail: string
     * }
     */
    public function extractForFile(int $fileId): array
    {
        $fail = static fn (string $msg, string $tail = '', bool $skip = false): array => [
            'ok'          => false,
            'skip'        => $skip,
            'srt_path'    => null,
            'message'     => $msg,
            'ffmpeg_tail' => $tail,
        ];

        $file = $this->files->findById($fileId);
        if ($file === null) {
            return $fail('File not found.');
        }

        $source = FileRepository::mediaSourcePath($file);
        if ($source === '' || !is_readable($source)) {
            return $fail('Media file is not readable: ' . $source);
        }

        $existing = FFprobeService::adjacentCaptionSidecar($source);
        if ($existing !== null && str_ends_with(strtolower($existing), '.srt')) {
            $this->files->recordSrtSidecar($fileId, $existing, true, null);

            return [
                'ok'          => true,
                'skip'        => false,
                'srt_path'    => $existing,
                'message'     => 'Existing SRT sidecar linked.',
                'ffmpeg_tail' => '',
            ];
        }

        if (!$this->isAvailable()) {
            return $fail('FFmpeg is not available at ' . $this->ffmpegBin);
        }

        $streamIndex = isset($file['caption_stream_index']) && $file['caption_stream_index'] !== null
            ? (int) $file['caption_stream_index']
            : null;
        $hasCaptions = !empty($file['has_captions']);
        $probeOk = !empty($file['captions_probed']) && ($hasCaptions || $streamIndex !== null);

        if ($streamIndex === null || !$hasCaptions) {
            $probe = $this->ffprobe->probe($source);
            if ($probe !== null) {
                $probeOk = true;
                if (isset($probe['caption_stream_index'])) {
                    $streamIndex = (int) $probe['caption_stream_index'];
                }
                if (!empty($probe['has_captions']) || $streamIndex !== null) {
                    $hasCaptions = true;
                    $this->files->updateCaptionFlags($fileId, true, $streamIndex);
                } else {
                    $this->files->updateCaptionFlags($fileId, false, null);
                }
            }
        }

        if (!$hasCaptions && $streamIndex === null) {
            if ($probeOk) {
                return $fail(
                    'No caption/subtitle stream detected. (CEA-608 in MXF may need a separate extractor.)',
                    '',
                    true
                );
            }

            return $fail('FFprobe failed — could not determine captions.', '', false);
        }

        $srtPath = dirname($source) . DIRECTORY_SEPARATOR
            . pathinfo($source, PATHINFO_FILENAME) . '.srt';
        $srtPath = str_replace('\\', '/', $srtPath);

        if (is_file($srtPath)) {
            @unlink($srtPath);
        }

        $mapArg = $streamIndex !== null
            ? '-map 0:' . $streamIndex
            : '-map 0:s:0';

        $inner = sprintf(
            '%s -y -i %s %s -c:s srt %s',
            escapeshellcmd($this->ffmpegBin),
            escapeshellarg($source),
            $mapArg,
            escapeshellarg($srtPath)
        );

        $cmd = $inner . ' 2>&1';
        if ($this->timeoutSeconds > 0 && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $cmd = 'timeout ' . $this->timeoutSeconds . 's ' . $inner . ' 2>&1';
        }

        $output = [];
        $code = 0;
        $t0 = microtime(true);
        exec($cmd, $output, $code);
        $wall = round(microtime(true) - $t0, 2);
        $tail = implode("\n", array_slice($output, -40));

        if ($code === 124) {
            return $fail(
                'FFmpeg timed out after ' . $this->timeoutSeconds . 's (wall ' . $wall . 's).',
                $tail
            );
        }

        if ($code !== 0 || !is_readable($srtPath) || filesize($srtPath) === 0) {
            if (is_file($srtPath)) {
                @unlink($srtPath);
            }

            return $fail(
                'FFmpeg could not extract captions (exit ' . $code . ', wall ' . $wall . 's).',
                $tail
            );
        }

        $cues = SrtCaptionParser::parseFile($srtPath);
        if ($cues === []) {
            @unlink($srtPath);

            return $fail('Extracted file had no usable caption cues (wall ' . $wall . 's).', $tail);
        }

        $this->files->recordSrtSidecar($fileId, $srtPath, true, $streamIndex);

        return [
            'ok'          => true,
            'skip'        => false,
            'srt_path'    => $srtPath,
            'message'     => 'Extracted ' . count($cues) . ' caption cue(s) to SRT (wall ' . $wall . 's).',
            'ffmpeg_tail' => $tail,
        ];
    }
}
