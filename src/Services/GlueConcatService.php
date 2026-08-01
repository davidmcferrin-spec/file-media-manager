<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\GlueQueueRepository;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Runs ffmpeg concat for a glue_queue job, registers the output file, and
 * deletes source parts only after explicit QC approval.
 */
final class GlueConcatService
{
    private string $ffmpegBin;

    public function __construct(
        private readonly GlueQueueRepository $jobs = new GlueQueueRepository(),
        private readonly FileRepository $files = new FileRepository(),
        private readonly FFprobeService $ffprobe = new FFprobeService(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
        $this->ffmpegBin = (string) env('FFMPEG_BIN', '/usr/bin/ffmpeg');
    }

    /**
     * Queue a glue group for concat.
     *
     * @return array{ok: bool, job_id: int|null, message: string}
     */
    public function queueGroup(string $glueGroupKey, int $userId): array
    {
        $glueGroupKey = trim($glueGroupKey);
        if ($glueGroupKey === '') {
            return ['ok' => false, 'job_id' => null, 'message' => 'Missing glue group key.'];
        }

        if ($this->jobs->findActiveByGroupKey($glueGroupKey) !== null) {
            return ['ok' => false, 'job_id' => null, 'message' => 'This group already has an active glue job.'];
        }

        $parts = $this->files->listByGlueGroupKey($glueGroupKey);
        if (count($parts) < 2) {
            return ['ok' => false, 'job_id' => null, 'message' => 'Glue group needs at least two parts.'];
        }

        foreach ($parts as $part) {
            if (!in_array((string) ($part['status'] ?? ''), ['PENDING', 'FLAGGED', 'REJECTED', 'APPROVED'], true)) {
                return [
                    'ok'      => false,
                    'job_id'  => null,
                    'message' => 'All source parts must be in a pre-execute catalog status.',
                ];
            }
            $path = FileRepository::mediaSourcePath($part);
            if ($path === '' || !is_readable($path)) {
                return [
                    'ok'      => false,
                    'job_id'  => null,
                    'message' => 'Unreadable source: ' . (string) ($part['original_filename'] ?? $part['id']),
                ];
            }
        }

        $ids = array_map(static fn (array $p): int => (int) $p['id'], $parts);
        $expected = 0.0;
        foreach ($parts as $part) {
            if (isset($part['duration_seconds']) && $part['duration_seconds'] !== null && $part['duration_seconds'] !== '') {
                $expected += (float) $part['duration_seconds'];
            }
        }

        try {
            $jobId = $this->jobs->create(
                $glueGroupKey,
                $ids,
                $userId,
                $expected > 0 ? $expected : null,
                sprintf('%d parts queued for ffmpeg concat', count($ids))
            );
        } catch (PDOException $e) {
            if ($this->jobs->isUniqueViolation($e)) {
                return ['ok' => false, 'job_id' => null, 'message' => 'This group already has an active glue job.'];
            }

            return ['ok' => false, 'job_id' => null, 'message' => 'Could not create glue job.'];
        }

        return [
            'ok'      => true,
            'job_id'  => $jobId,
            'message' => 'Glue job #' . $jobId . ' queued.',
        ];
    }

    /**
     * Run ffmpeg concat for a PENDING or FAILED job.
     *
     * @return array{ok: bool, message: string}
     */
    public function run(int $jobId, int $userId, string $userEmail, string $ip): array
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            return ['ok' => false, 'message' => 'Glue job not found.'];
        }
        if (!in_array((string) $job['status'], ['PENDING', 'FAILED'], true)) {
            return ['ok' => false, 'message' => 'Job must be PENDING or FAILED to run.'];
        }

        if (!is_executable($this->ffmpegBin)) {
            return ['ok' => false, 'message' => 'ffmpeg binary not executable: ' . $this->ffmpegBin];
        }

        if (!$this->jobs->markRunning($jobId)) {
            return ['ok' => false, 'message' => 'Could not mark job as RUNNING.'];
        }

        $this->audit->record($userId, $userEmail, $ip, 'GLUE_RUN_STARTED', 'glue_queue', $jobId, null, null, [
            'glue_group_key' => $job['glue_group_key'],
        ]);

        @set_time_limit(0);
        @ignore_user_abort(true);

        $listPath = null;
        $outputPath = null;
        $outputFileId = 0;
        try {
            $sourceIds = $this->jobs->parseSourceIds($job['source_file_ids'] ?? '[]');
            $parts = $this->loadOrderedParts((string) $job['glue_group_key'], $sourceIds);
            if (count($parts) < 2) {
                throw new RuntimeException('Need at least two readable source parts.');
            }

            $paths = [];
            foreach ($parts as $part) {
                $path = FileRepository::mediaSourcePath($part);
                if ($path === '' || !is_readable($path)) {
                    throw new RuntimeException(
                        'Unreadable source: ' . (string) ($part['original_filename'] ?? $part['id'])
                    );
                }
                $paths[] = $path;
            }

            $primary = $parts[0];
            $outputPath = self::suggestOutputPath($paths[0]);
            if ($outputPath === '') {
                throw new RuntimeException('Could not determine output path.');
            }

            $listPath = $this->writeConcatList($paths);
            $ffmpegOut = $this->runFfmpegConcat($listPath, $outputPath);
            if (!is_readable($outputPath) || filesize($outputPath) === 0) {
                throw new RuntimeException(
                    'ffmpeg concat produced no output.'
                    . ($ffmpegOut !== '' ? ' ' . $ffmpegOut : '')
                );
            }

            $meta = $this->ffprobe->probe($outputPath) ?? [];
            $duration = isset($meta['duration']) ? (float) $meta['duration'] : null;
            $filesize = isset($meta['filesize_bytes'])
                ? (int) $meta['filesize_bytes']
                : (int) filesize($outputPath);

            $outputFileId = $this->registerOutputFile($primary, $parts, $outputPath, $meta, $jobId);

            if (!$this->jobs->markReadyForQc($jobId, $outputPath, $outputFileId, $duration, $filesize)) {
                throw new RuntimeException('Concat finished but failed to mark READY_FOR_QC.');
            }

            $this->audit->record(
                $userId,
                $userEmail,
                $ip,
                'GLUE_RUN_COMPLETED',
                'glue_queue',
                $jobId,
                implode(' + ', array_map(static fn (array $p): string => (string) $p['original_path'], $parts)),
                $outputPath,
                [
                    'output_file_id' => $outputFileId,
                    'duration'       => $duration,
                    'filesize'       => $filesize,
                ]
            );

            return [
                'ok'      => true,
                'message' => 'Concat complete — ready for QC (job #' . $jobId . ').',
            ];
        } catch (Throwable $e) {
            if ($outputFileId > 0) {
                $this->files->deleteRemovable($outputFileId);
            }
            if (is_string($outputPath) && $outputPath !== '' && is_file($outputPath)) {
                @unlink($outputPath);
            }
            $msg = $e->getMessage();
            $this->jobs->markFailed($jobId, $msg);
            $this->audit->record($userId, $userEmail, $ip, 'GLUE_RUN_FAILED', 'glue_queue', $jobId, null, null, [
                'error' => $msg,
            ]);

            return ['ok' => false, 'message' => $msg];
        } finally {
            if (is_string($listPath) && is_file($listPath)) {
                @unlink($listPath);
            }
        }
    }

    /**
     * QC approve: output accepted; sources remain until explicit delete.
     *
     * @return array{ok: bool, message: string}
     */
    public function approveQc(int $jobId, int $userId, string $userEmail, string $ip): array
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || (string) $job['status'] !== 'READY_FOR_QC') {
            return ['ok' => false, 'message' => 'Job is not awaiting QC.'];
        }

        if (!$this->jobs->markApproved($jobId, $userId)) {
            return ['ok' => false, 'message' => 'Could not approve QC.'];
        }

        $this->audit->record($userId, $userEmail, $ip, 'GLUE_QC_APPROVED', 'glue_queue', $jobId, null, $job['output_path'] ?? null, [
            'output_file_id' => $job['output_file_id'] ?? null,
        ]);

        return ['ok' => true, 'message' => 'QC approved. Delete source parts when ready.'];
    }

    /**
     * Reject QC: remove output file/row and allow re-run.
     *
     * @return array{ok: bool, message: string}
     */
    public function rejectQc(int $jobId, int $userId, string $userEmail, string $ip): array
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || !in_array((string) $job['status'], ['READY_FOR_QC', 'APPROVED'], true)) {
            return ['ok' => false, 'message' => 'Job is not in a QC state.'];
        }

        $outputPath = (string) ($job['output_path'] ?? '');
        $outputFileId = (int) ($job['output_file_id'] ?? 0);

        if ($outputFileId > 0) {
            $this->files->deleteRemovable($outputFileId);
        }
        if ($outputPath !== '' && is_file($outputPath)) {
            @unlink($outputPath);
        }

        $this->jobs->clearOutputRef($jobId);
        $this->jobs->markFailed($jobId, 'QC rejected — output discarded; re-run when ready.');

        $this->audit->record($userId, $userEmail, $ip, 'GLUE_QC_REJECTED', 'glue_queue', $jobId, $outputPath, null, [
            'output_file_id' => $outputFileId > 0 ? $outputFileId : null,
        ]);

        return ['ok' => true, 'message' => 'QC rejected; output removed. Job marked FAILED — re-run to try again.'];
    }

    /**
     * Delete source parts from disk + catalog after QC approval.
     *
     * @return array{ok: bool, message: string, deleted: int}
     */
    public function deleteSources(int $jobId, int $userId, string $userEmail, string $ip): array
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || (string) $job['status'] !== 'APPROVED') {
            return ['ok' => false, 'message' => 'Approve QC before deleting sources.', 'deleted' => 0];
        }

        $outputPath = (string) ($job['output_path'] ?? '');
        if ($outputPath === '' || !is_readable($outputPath)) {
            return ['ok' => false, 'message' => 'Glued output is missing — cannot delete sources.', 'deleted' => 0];
        }

        $sourceIds = $this->jobs->parseSourceIds($job['source_file_ids'] ?? '[]');
        $deleted = 0;
        $errors = [];

        foreach ($sourceIds as $fileId) {
            $file = $this->files->findById($fileId);
            if ($file === null) {
                continue;
            }
            if ((int) ($job['output_file_id'] ?? 0) === $fileId) {
                continue;
            }

            $diskPath = FileRepository::mediaSourcePath($file);
            $this->audit->record(
                $userId,
                $userEmail,
                $ip,
                'GLUE_SOURCE_DELETE',
                'file',
                $fileId,
                (string) ($file['original_path'] ?? $diskPath),
                $outputPath,
                ['glue_job_id' => $jobId]
            );

            if ($diskPath !== '' && is_file($diskPath)) {
                if (!@unlink($diskPath)) {
                    $errors[] = 'Could not delete disk file: ' . basename($diskPath);
                    continue;
                }
            }

            if ($this->files->deleteRemovable($fileId)) {
                $deleted++;
            } else {
                $errors[] = 'Could not remove catalog row #' . $fileId;
            }
        }

        if ($errors !== []) {
            return [
                'ok'      => false,
                'message' => 'Partial source delete: ' . implode('; ', $errors),
                'deleted' => $deleted,
            ];
        }

        if (!$this->jobs->markDone($jobId)) {
            return [
                'ok'      => false,
                'message' => 'Sources deleted but failed to mark job DONE.',
                'deleted' => $deleted,
            ];
        }

        $this->audit->record($userId, $userEmail, $ip, 'GLUE_SOURCES_DELETED', 'glue_queue', $jobId, null, $outputPath, [
            'deleted_count' => $deleted,
        ]);

        return [
            'ok'      => true,
            'message' => $deleted . ' source part(s) deleted. Glue job complete.',
            'deleted' => $deleted,
        ];
    }

    /**
     * Suggest a unique output path beside the first part: Name_GLUED.ext
     */
    public static function suggestOutputPath(string $primaryPath): string
    {
        $primaryPath = str_replace('\\', '/', $primaryPath);
        $dir = dirname($primaryPath);
        $filename = basename($primaryPath);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($stem === '') {
            return '';
        }

        // Strip trailing _N so Episode_1 → Episode_GLUED
        if (preg_match('/^(.+)_(\d+)$/', $stem, $m) === 1) {
            $stem = $m[1];
        }

        $suffix = $ext !== '' ? '.' . $ext : '';
        $candidate = $dir . '/' . $stem . '_GLUED' . $suffix;
        $n = 2;
        while (is_file($candidate)) {
            $candidate = $dir . '/' . $stem . '_GLUED_' . $n . $suffix;
            $n++;
            if ($n > 100) {
                return '';
            }
        }

        return $candidate;
    }

    /**
     * Duration QC helper: true when output is within tolerance of expected sum.
     */
    public static function durationLooksOk(?float $expected, ?float $actual, float $toleranceSeconds = 2.0): bool
    {
        if ($expected === null || $actual === null || $expected <= 0 || $actual <= 0) {
            return true; // insufficient data — do not block QC
        }
        $delta = abs($expected - $actual);
        $pctTol = max($toleranceSeconds, $expected * 0.02);

        return $delta <= $pctTol;
    }

    /**
     * @param list<int> $sourceIds
     * @return list<array<string, mixed>>
     */
    private function loadOrderedParts(string $groupKey, array $sourceIds): array
    {
        $byGroup = $this->files->listByGlueGroupKey($groupKey);
        if ($byGroup !== []) {
            return $byGroup;
        }

        // Fallback: load by stored ids (flags may have been cleared)
        $parts = [];
        foreach ($sourceIds as $id) {
            $row = $this->files->findById($id);
            if ($row !== null) {
                $parts[] = $row;
            }
        }
        usort($parts, static function (array $a, array $b): int {
            $ia = $a['glue_part_index'] ?? null;
            $ib = $b['glue_part_index'] ?? null;
            if ($ia !== null && $ib !== null && (int) $ia !== (int) $ib) {
                return (int) $ia <=> (int) $ib;
            }

            return strcasecmp((string) ($a['original_filename'] ?? ''), (string) ($b['original_filename'] ?? ''));
        });

        return $parts;
    }

    /**
     * @param list<string> $paths
     */
    private function writeConcatList(array $paths): string
    {
        $tmpDir = dirname(__DIR__, 2) . '/storage/tmp';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Could not create storage/tmp for concat list.');
        }

        $listPath = $tmpDir . '/glue_concat_' . bin2hex(random_bytes(8)) . '.txt';
        $lines = [];
        foreach ($paths as $path) {
            $escaped = str_replace("'", "'\\''", $path);
            $lines[] = "file '" . $escaped . "'";
        }
        if (file_put_contents($listPath, implode("\n", $lines) . "\n") === false) {
            throw new RuntimeException('Could not write ffmpeg concat list.');
        }

        return $listPath;
    }

    private function runFfmpegConcat(string $listPath, string $outputPath): string
    {
        $cmd = sprintf(
            '%s -hide_banner -nostdin -loglevel error -f concat -safe 0 -i %s -c copy -y %s 2>&1',
            escapeshellcmd($this->ffmpegBin),
            escapeshellarg($listPath),
            escapeshellarg($outputPath)
        );

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $text = trim(implode("\n", $output));

        if ($code !== 0) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            throw new RuntimeException(
                'ffmpeg concat failed (exit ' . $code . ').'
                . ($text !== '' ? ' ' . $text : ' Parts may have mismatched codecs — stream copy requires identical streams.')
            );
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $primary
     * @param list<array<string, mixed>> $parts
     * @param array<string, mixed> $meta
     */
    private function registerOutputFile(
        array $primary,
        array $parts,
        string $outputPath,
        array $meta,
        int $jobId
    ): int {
        $outputPath = str_replace('\\', '/', $outputPath);
        if ($this->files->existsByOriginalPath($outputPath)) {
            throw new RuntimeException('Output path already exists in catalog: ' . $outputPath);
        }

        $sourceIds = array_map(static fn (array $p): int => (int) $p['id'], $parts);
        $notes = [
            'glued'            => true,
            'glue_job_id'      => $jobId,
            'source_file_ids'  => $sourceIds,
            'source_filenames' => array_map(
                static fn (array $p): string => (string) ($p['original_filename'] ?? ''),
                $parts
            ),
        ];

        return $this->files->insert([
            'scan_job_id'                   => $primary['scan_job_id'] ?? null,
            'source_id'                     => $primary['source_id'] ?? null,
            'original_path'                 => $outputPath,
            'original_dir'                  => str_replace('\\', '/', dirname($outputPath)),
            'original_filename'             => basename($outputPath),
            'proposed_dir'                  => $primary['proposed_dir'] ?? null,
            'proposed_filename'             => $primary['proposed_filename'] ?? null,
            'show_id'                       => $primary['show_id'] ?? null,
            'media_type_id'                 => $primary['media_type_id'] ?? null,
            'file_date'                     => $primary['file_date'] ?? null,
            'file_time'                     => $primary['file_time'] ?? null,
            'confidence'                    => $primary['confidence'] ?? 'UNEVALUATED',
            'classifier_notes'              => json_encode($notes, JSON_THROW_ON_ERROR),
            'status'                        => 'PENDING',
            'duration_seconds'              => $meta['duration'] ?? null,
            'filesize_bytes'                => $meta['filesize_bytes'] ?? (is_file($outputPath) ? filesize($outputPath) : null),
            'container'                     => $meta['container'] ?? null,
            'codec_video'                   => $meta['codec_video'] ?? null,
            'codec_audio'                   => $meta['codec_audio'] ?? null,
            'resolution'                    => $meta['resolution'] ?? null,
            'framerate'                     => $meta['framerate'] ?? null,
            'metadata_extracted'            => true,
            'needs_split'                   => false,
            'split_notes'                   => '',
            'needs_glue'                    => false,
            'glue_group_key'                => null,
            'glue_part_index'               => null,
            'glue_notes'                    => '',
            'classifier_confidence'         => $primary['confidence'] ?? 'UNEVALUATED',
            'classifier_proposed_dir'       => $primary['proposed_dir'] ?? null,
            'classifier_proposed_filename'  => $primary['proposed_filename'] ?? null,
            'proposed_source'               => $primary['proposed_source'] ?? 'classifier',
        ]);
    }
}
