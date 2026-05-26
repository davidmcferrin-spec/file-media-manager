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
}
