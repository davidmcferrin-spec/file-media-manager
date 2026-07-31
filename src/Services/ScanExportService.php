<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ScanJobRepository;

final class ScanExportService
{
    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly ScanJobRepository $scanJobs = new ScanJobRepository(),
    ) {
    }

    /**
     * @return array{filename: string, bytes: string, row_count: int}
     */
    public function exportScanJob(int $scanJobId): array
    {
        $job = $this->scanJobs->findById($scanJobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Scan job not found.');
        }

        $files = $this->files->byScanJob($scanJobId, 100000);
        $rows = [$this->header()];
        foreach ($files as $file) {
            $rows[] = $this->mapRow($file, $job);
        }

        $sheetName = 'Scan ' . $scanJobId;
        $bytes = SimpleXlsxWriter::writeToString($rows, $sheetName);
        $source = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($job['source_name'] ?? 'scan')) ?: 'scan';
        $filename = sprintf('scan_%d_%s_classification.xlsx', $scanJobId, $source);

        return [
            'filename'  => $filename,
            'bytes'     => $bytes,
            'row_count' => count($files),
        ];
    }

    /** @return list<string> */
    private function header(): array
    {
        return [
            'scan_job_id',
            'file_id',
            'status',
            'confidence',
            'proposed_source',
            'original_path',
            'original_filename',
            'show_title',
            'show_abbr',
            'file_date',
            'file_time',
            'media_type',
            'media_type_abbr',
            'proposed_dir',
            'proposed_filename',
            'classifier_proposed_dir',
            'classifier_proposed_filename',
            'alt_proposed_dir',
            'alt_proposed_filename',
            'duration_seconds',
            'needs_split',
            'needs_glue',
            'glue_group_key',
            'glue_part_index',
            'source_name',
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $job
     * @return list<string|int|float|null>
     */
    private function mapRow(array $file, array $job): array
    {
        $needsSplit = !empty($file['needs_split']);
        $needsGlue = !empty($file['needs_glue']);

        return [
            (int) ($file['scan_job_id'] ?? $job['id'] ?? 0),
            (int) ($file['id'] ?? 0),
            (string) ($file['status'] ?? ''),
            (string) ($file['confidence'] ?? ''),
            (string) ($file['proposed_source'] ?? ''),
            (string) ($file['original_path'] ?? ''),
            (string) ($file['original_filename'] ?? ''),
            (string) ($file['show_name'] ?? ''),
            (string) ($file['show_abbr'] ?? ''),
            (string) ($file['file_date'] ?? ''),
            (string) ($file['file_time'] ?? ''),
            (string) ($file['media_type_name'] ?? ''),
            (string) ($file['media_type_abbr'] ?? ''),
            (string) ($file['proposed_dir'] ?? ''),
            (string) ($file['proposed_filename'] ?? ''),
            (string) ($file['classifier_proposed_dir'] ?? ''),
            (string) ($file['classifier_proposed_filename'] ?? ''),
            (string) ($file['alt_proposed_dir'] ?? ''),
            (string) ($file['alt_proposed_filename'] ?? ''),
            $file['duration_seconds'] !== null && $file['duration_seconds'] !== ''
                ? (float) $file['duration_seconds']
                : '',
            $needsSplit ? 'yes' : 'no',
            $needsGlue ? 'yes' : 'no',
            (string) ($file['glue_group_key'] ?? ''),
            $file['glue_part_index'] !== null && $file['glue_part_index'] !== ''
                ? (int) $file['glue_part_index']
                : '',
            (string) ($job['source_name'] ?? ''),
        ];
    }
}
