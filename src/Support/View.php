<?php

declare(strict_types=1);

namespace MediaManager\Support;

class View
{
    /**
     * Escape a value for safe HTML output.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Format bytes as human-readable size.
     */
    public static function filesize(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }

    /**
     * Format seconds as H:MM:SS
     */
    public static function duration(int|float|null $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $s = (int) $seconds;
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $s = $s % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    /**
     * Compact technical summary for queue Meta column (from scan-time ffprobe fields).
     *
     * @param array<string, mixed> $file
     */
    public static function mediaTechSummary(array $file): string
    {
        $parts = [];
        if (!empty($file['resolution'])) {
            $parts[] = (string) $file['resolution'];
        }
        if (!empty($file['codec_video'])) {
            $parts[] = strtoupper((string) $file['codec_video']);
        }
        if (!empty($file['codec_audio'])) {
            $parts[] = 'A:' . strtoupper((string) $file['codec_audio']);
        }
        if (!empty($file['container'])) {
            $parts[] = strtoupper((string) $file['container']);
        }
        if ($parts === []) {
            return empty($file['metadata_extracted']) ? 'No metadata' : '—';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public static function mediaMetaPayload(array $file): array
    {
        return [
            'duration'            => $file['duration_seconds'] ?? null,
            'duration_label'      => self::duration(
                isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : null
            ),
            'resolution'          => $file['resolution'] ?? null,
            'codec_video'         => $file['codec_video'] ?? null,
            'codec_audio'         => $file['codec_audio'] ?? null,
            'framerate'           => $file['framerate'] ?? null,
            'container'           => $file['container'] ?? null,
            'filesize_bytes'      => $file['filesize_bytes'] ?? null,
            'filesize_label'      => self::filesize(
                isset($file['filesize_bytes']) ? (int) $file['filesize_bytes'] : null
            ),
            'metadata_extracted'  => !empty($file['metadata_extracted']),
        ];
    }

    /**
     * Confidence badge HTML.
     */
    public static function confidenceBadge(string $confidence): string
    {
        $map = [
            'HIGH'   => ['success', 'HIGH'],
            'MEDIUM' => ['warning', 'MED'],
            'LOW'    => ['danger',  'LOW'],
        ];
        [$cls, $label] = $map[$confidence] ?? ['secondary', $confidence];
        return '<span class="badge bg-' . $cls . '">' . $label . '</span>';
    }

    /**
     * Status badge HTML.
     */
    public static function statusBadge(string $status): string
    {
        $map = [
            'PENDING'     => ['secondary', 'Pending'],
            'APPROVED'    => ['primary',   'Approved'],
            'REJECTED'    => ['dark',      'Rejected'],
            'FLAGGED'     => ['warning',   'Flagged'],
            'EXECUTED'    => ['success',   'Executed'],
            'ROLLED_BACK' => ['info',      'Rolled Back'],
            'IN_PROGRESS' => ['info',      'In Progress'],
            'DONE'        => ['success',   'Done'],
            'FAILED'      => ['danger',    'Failed'],
        ];
        [$cls, $label] = $map[$status] ?? ['secondary', $status];
        return '<span class="badge bg-' . $cls . '">' . self::e($label) . '</span>';
    }

    /**
     * Page numbers for pagination UI: [1, '…', 4, 5, 6, '…', 100]
     *
     * @return list<int|string>
     */
    public static function paginationRange(int $page, int $totalPages, int $window = 2): array
    {
        if ($totalPages <= 1) {
            return [1];
        }

        $page = max(1, min($page, $totalPages));
        $pages = [1];

        $left  = max(2, $page - $window);
        $right = min($totalPages - 1, $page + $window);

        if ($left > 2) {
            $pages[] = '…';
        }

        for ($p = $left; $p <= $right; $p++) {
            $pages[] = $p;
        }

        if ($right < $totalPages - 1) {
            $pages[] = '…';
        }

        if ($totalPages > 1) {
            $pages[] = $totalPages;
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function paginationUrl(string $basePath, array $query, int $page): string
    {
        $params = $query;
        if ($page > 1) {
            $params['page'] = (string) $page;
        } else {
            unset($params['page']);
        }

        $filtered = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false
        );

        $qs = http_build_query($filtered);

        return $basePath . ($qs !== '' ? '?' . $qs : '');
    }
}
