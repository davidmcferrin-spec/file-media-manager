<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Export / cut policy for approved split segments.
 *
 * Operators mark the show itself (program content). On export, the system adds
 * up to HANDLE_SECONDS before Mark In and after Mark Out when media exists —
 * so reviewers should not pad marks manually.
 */
final class SplitExportPolicy
{
    /** Seconds of handle before Mark In and after Mark Out (when available). */
    public const HANDLE_SECONDS = 300;

    /**
     * @return array{
     *   mark_in: float,
     *   mark_out: float,
     *   export_start: float,
     *   export_end: float,
     *   pad_before: float,
     *   pad_after: float,
     *   handle_seconds: int
     * }
     */
    public static function exportRange(float $markIn, float $markOut, ?float $fileDurationSeconds): array
    {
        $markIn = max(0.0, $markIn);
        $markOut = max($markIn, $markOut);
        $duration = $fileDurationSeconds !== null && $fileDurationSeconds > 0
            ? $fileDurationSeconds
            : null;

        $exportStart = max(0.0, $markIn - self::HANDLE_SECONDS);
        $exportEnd = $markOut + self::HANDLE_SECONDS;
        if ($duration !== null) {
            $exportEnd = min($duration, $exportEnd);
        }

        return [
            'mark_in'         => $markIn,
            'mark_out'        => $markOut,
            'export_start'    => round($exportStart, 3),
            'export_end'      => round($exportEnd, 3),
            'pad_before'      => round($markIn - $exportStart, 3),
            'pad_after'       => round($exportEnd - $markOut, 3),
            'handle_seconds'  => self::HANDLE_SECONDS,
        ];
    }

    public static function handleMinutes(): int
    {
        return (int) (self::HANDLE_SECONDS / 60);
    }
}
