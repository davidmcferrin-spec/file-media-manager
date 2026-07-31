<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\ContinuityCheckLogRepository;

final class ContinuityLabExportService
{
    public const MAX_ROWS = 60000;

    private const BATCH = 5000;

    public function __construct(
        private readonly ContinuityCheckLogRepository $log = new ContinuityCheckLogRepository(),
    ) {
    }

    /**
     * @param array{outcome?: string, q?: string} $filters
     * @return array{filename: string, bytes: string, row_count: int}
     */
    public function export(array $filters = []): array
    {
        $rows = [$this->header()];
        $exported = 0;
        $offset = 0;

        while ($exported < self::MAX_ROWS) {
            $batchSize = min(self::BATCH, self::MAX_ROWS - $exported);
            $entries = $this->log->listForExport($filters, $batchSize, $offset);
            if ($entries === []) {
                break;
            }
            foreach ($entries as $entry) {
                $rows[] = $this->mapRow($entry);
                $exported++;
                if ($exported >= self::MAX_ROWS) {
                    break 2;
                }
            }
            $offset += count($entries);
            if (count($entries) < $batchSize) {
                break;
            }
        }

        $bytes = SimpleXlsxWriter::writeToString($rows, 'Continuity Lab');
        $filename = 'continuity_lab_' . date('Ymd_His') . '.xlsx';

        return [
            'filename'  => $filename,
            'bytes'     => $bytes,
            'row_count' => $exported,
        ];
    }

    /** @return list<string> */
    private function header(): array
    {
        return [
            'id',
            'created_at',
            'outcome',
            'duration_ms',
            'file_id',
            'rule_confidence',
            'final_confidence',
            'rule_show_id',
            'rule_show_abbr',
            'final_show_id',
            'final_show_abbr',
            'rule_file_date',
            'rule_file_time',
            'engine_file_date',
            'engine_file_time',
            'final_file_date',
            'final_file_time',
            'rule_media_type_id',
            'rule_media_type_abbr',
            'engine_media_type_id',
            'engine_media_type_abbr',
            'final_media_type_id',
            'final_media_type_abbr',
            'engine_agree',
            'engine_confidence',
            'engine_show_id',
            'engine_reason',
            'signal',
            'original_path',
            'original_filename',
            'rule_proposed_filename',
            'final_proposed_filename',
            'rule_signals',
            'http_status',
            'transport_error',
            'seed_shows_count',
            'seed_timeline_count',
            'seed_examples_count',
            'engine_raw',
            'seed_packet_json',
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string|int|float|null>
     */
    private function mapRow(array $entry): array
    {
        $signals = $entry['rule_signals'] ?? [];
        if (is_string($signals)) {
            $decoded = json_decode($signals, true);
            $signals = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($signals)) {
            $signals = [];
        }

        $agree = $entry['engine_agree'] ?? null;
        $agreeOut = null;
        if ($agree !== null) {
            $agreeOut = (!empty($agree) && $agree !== 'f' && $agree !== 'false') ? 'yes' : 'no';
        }

        return [
            (int) ($entry['id'] ?? 0),
            (string) ($entry['created_at'] ?? ''),
            (string) ($entry['outcome'] ?? ''),
            (int) ($entry['duration_ms'] ?? 0),
            $entry['file_id'] ?? null,
            (string) ($entry['rule_confidence'] ?? ''),
            (string) ($entry['final_confidence'] ?? ''),
            $entry['rule_show_id'] ?? null,
            (string) ($entry['rule_show_abbr'] ?? ''),
            $entry['final_show_id'] ?? null,
            (string) ($entry['final_show_abbr'] ?? ''),
            (string) ($entry['rule_file_date'] ?? ''),
            (string) ($entry['rule_file_time'] ?? ''),
            (string) ($entry['engine_file_date'] ?? ''),
            (string) ($entry['engine_file_time'] ?? ''),
            (string) ($entry['final_file_date'] ?? ''),
            (string) ($entry['final_file_time'] ?? ''),
            $entry['rule_media_type_id'] ?? null,
            (string) ($entry['rule_media_type_abbr'] ?? ''),
            $entry['engine_media_type_id'] ?? null,
            (string) ($entry['engine_media_type_abbr'] ?? ''),
            $entry['final_media_type_id'] ?? null,
            (string) ($entry['final_media_type_abbr'] ?? ''),
            $agreeOut,
            (string) ($entry['engine_confidence'] ?? ''),
            $entry['engine_show_id'] ?? null,
            (string) ($entry['engine_reason'] ?? ''),
            (string) ($entry['signal'] ?? ''),
            (string) ($entry['original_path'] ?? ''),
            (string) ($entry['original_filename'] ?? ''),
            (string) ($entry['rule_proposed_filename'] ?? ''),
            (string) ($entry['final_proposed_filename'] ?? ''),
            implode(' | ', array_map('strval', $signals)),
            $entry['http_status'] ?? null,
            (string) ($entry['transport_error'] ?? ''),
            (int) ($entry['seed_shows_count'] ?? 0),
            (int) ($entry['seed_timeline_count'] ?? 0),
            (int) ($entry['seed_examples_count'] ?? 0),
            (string) ($entry['engine_raw'] ?? ''),
            (string) ($entry['seed_packet_json'] ?? ''),
        ];
    }
}
