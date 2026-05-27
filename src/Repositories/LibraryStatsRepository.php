<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class LibraryStatsRepository extends BaseRepository
{
    /** @return list<array{label: string, count: int}> */
    public function extensionBreakdown(int $limit = 12): array
    {
        $stmt = $this->db()->query(
            "SELECT LOWER(substring(original_filename from '\\.([^.]+)$')) AS label,
                    COUNT(*) AS count
             FROM files
             WHERE original_filename ~ '\\.[^.]+$'
             GROUP BY label
             ORDER BY count DESC, label ASC"
        );
        $rows = $stmt->fetchAll();

        return $this->bucketTop(is_array($rows) ? $rows : [], $limit);
    }

    /** @return list<array{label: string, count: int}> */
    public function resolutionBreakdown(int $limit = 12): array
    {
        $stmt = $this->db()->query(
            "SELECT COALESCE(NULLIF(trim(resolution), ''), 'Unknown') AS label,
                    COUNT(*) AS count
             FROM files
             GROUP BY label
             ORDER BY count DESC, label ASC"
        );
        $rows = $stmt->fetchAll();

        return $this->bucketTop(is_array($rows) ? $rows : [], $limit);
    }

    /** @return list<array{label: string, count: int}> */
    public function codecBreakdown(int $limit = 12): array
    {
        $stmt = $this->db()->query(
            "SELECT COALESCE(NULLIF(trim(codec_video), ''), 'Unknown') AS label,
                    COUNT(*) AS count
             FROM files
             GROUP BY label
             ORDER BY count DESC, label ASC"
        );
        $rows = $stmt->fetchAll();

        return $this->bucketTop(
            array_map(static fn (array $row): array => [
                'label' => strtoupper((string) $row['label']),
                'count' => (int) $row['count'],
            ], is_array($rows) ? $rows : []),
            $limit
        );
    }

    /** @return list<array{label: string, total_seconds: float, file_count: int, href: string}> */
    public function hoursByYear(): array
    {
        $stmt = $this->db()->query(
            "SELECT substring(file_date from 1 for 4) AS year_key,
                    COALESCE(SUM(duration_seconds), 0) AS total_seconds,
                    COUNT(*) AS file_count
             FROM files
             WHERE file_date ~ '^\\d{8}$'
               AND duration_seconds IS NOT NULL
               AND duration_seconds > 0
             GROUP BY year_key
             ORDER BY year_key ASC"
        );
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $year = (string) ($row['year_key'] ?? '');
            if ($year === '') {
                continue;
            }
            $out[] = [
                'label'         => $year,
                'total_seconds' => (float) ($row['total_seconds'] ?? 0),
                'file_count'    => (int) ($row['file_count'] ?? 0),
                'href'          => '/dashboard/library?view=month&from=' . rawurlencode($year . '-01'),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{label: string, ym: string, total_seconds: float, file_count: int}>
     */
    public function hoursByMonthWindow(string $fromYm, int $months = 13): array
    {
        $keys = $this->monthWindowKeys($fromYm, $months);
        if ($keys === []) {
            return [];
        }

        $startYm = str_replace('-', '', $keys[0]) . '01';
        $endParts = explode('-', $keys[count($keys) - 1]);
        $endYm    = ($endParts[0] ?? '') . ($endParts[1] ?? '') . '31';

        $stmt = $this->db()->prepare(
            "SELECT substring(file_date from 1 for 6) AS ym_key,
                    COALESCE(SUM(duration_seconds), 0) AS total_seconds,
                    COUNT(*) AS file_count
             FROM files
             WHERE file_date ~ '^\\d{8}$'
               AND duration_seconds IS NOT NULL
               AND duration_seconds > 0
               AND file_date >= ?
               AND file_date <= ?
             GROUP BY ym_key"
        );
        $stmt->execute([$startYm, $endYm]);
        $rows = $stmt->fetchAll();

        $byYm = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $ymKey = (string) ($row['ym_key'] ?? '');
                if ($ymKey === '') {
                    continue;
                }
                $byYm[$ymKey] = [
                    'total_seconds' => (float) ($row['total_seconds'] ?? 0),
                    'file_count'    => (int) ($row['file_count'] ?? 0),
                ];
            }
        }

        $out = [];
        foreach ($keys as $ym) {
            $ymKey = str_replace('-', '', $ym);
            $data  = $byYm[$ymKey] ?? ['total_seconds' => 0.0, 'file_count' => 0];
            $out[] = [
                'label'         => $this->formatMonthLabel($ym),
                'ym'            => $ym,
                'total_seconds' => $data['total_seconds'],
                'file_count'    => $data['file_count'],
            ];
        }

        return $out;
    }

    /** @return array{undated_files: int, undated_seconds: float} */
    public function timelineExcludedSummary(): array
    {
        $row = $this->db()->query(
            "SELECT COUNT(*) AS undated_files,
                    COALESCE(SUM(duration_seconds), 0) AS undated_seconds
             FROM files
             WHERE duration_seconds IS NOT NULL
               AND duration_seconds > 0
               AND (file_date IS NULL OR file_date !~ '^\\d{8}$')"
        )->fetch();

        return [
            'undated_files'  => (int) ($row['undated_files'] ?? 0),
            'undated_seconds' => (float) ($row['undated_seconds'] ?? 0),
        ];
    }

    /** @return list<string> YYYY-MM keys */
    private function monthWindowKeys(string $fromYm, int $months): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $fromYm)) {
            return [];
        }

        try {
            $dt = new \DateTimeImmutable($fromYm . '-01');
        } catch (\Exception) {
            return [];
        }

        $keys = [];
        for ($i = 0; $i < $months; $i++) {
            $keys[] = $dt->format('Y-m');
            $dt     = $dt->modify('+1 month');
        }

        return $keys;
    }

    private function formatMonthLabel(string $ym): string
    {
        try {
            $dt = new \DateTimeImmutable($ym . '-01');
        } catch (\Exception) {
            return $ym;
        }

        return $dt->format("M 'y");
    }

    /** @return array{total_seconds: float, files_with_duration: int, total_files: int} */
    public function durationSummary(): array
    {
        $row = $this->db()->query(
            'SELECT COALESCE(SUM(duration_seconds), 0) AS total_seconds,
                    COUNT(*) FILTER (WHERE duration_seconds IS NOT NULL AND duration_seconds > 0) AS files_with_duration,
                    COUNT(*) AS total_files
             FROM files'
        )->fetch();

        return [
            'total_seconds'        => (float) ($row['total_seconds'] ?? 0),
            'files_with_duration'  => (int) ($row['files_with_duration'] ?? 0),
            'total_files'          => (int) ($row['total_files'] ?? 0),
        ];
    }

    /**
     * @param list<array{label: string, count: int|string}> $rows
     * @return list<array{label: string, count: int}>
     */
    private function bucketTop(array $rows, int $limit): array
    {
        if ($rows === []) {
            return [];
        }

        $mapped = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = 'Unknown';
            }
            $mapped[] = [
                'label' => $label,
                'count' => (int) ($row['count'] ?? 0),
            ];
        }

        if (count($mapped) <= $limit) {
            return $mapped;
        }

        $top    = array_slice($mapped, 0, $limit);
        $other  = array_slice($mapped, $limit);
        $otherCount = array_sum(array_column($other, 'count'));
        if ($otherCount > 0) {
            $top[] = ['label' => 'Other', 'count' => $otherCount];
        }

        return $top;
    }
}
