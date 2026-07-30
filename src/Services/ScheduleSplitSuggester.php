<?php

declare(strict_types=1);

namespace MediaManager\Services;

final class ScheduleSplitSuggester
{
    public const SPLIT_THRESHOLD_SECONDS = 4500; // 75 minutes

    public function __construct(
        private readonly ScheduleLookupService $lookup = new ScheduleLookupService(),
    ) {
    }

    /**
     * @return array{
     *   needs_split: bool,
     *   segments: list<array{start_offset: float, end_offset: float, show_id: ?int, show_abbr: ?string, title: string}>,
     *   notes: string,
     *   signals: list<string>
     * }
     */
    public function suggest(?string $dateYmd, ?string $timeHhmm, ?float $durationSeconds): array
    {
        $empty = [
            'needs_split' => false,
            'segments'    => [],
            'notes'       => '',
            'signals'     => [],
        ];

        if ($dateYmd === null || $timeHhmm === null || $durationSeconds === null || $durationSeconds <= 0) {
            return $empty;
        }

        $startMinutes = DateNormalizer::timeToMinutes($timeHhmm);
        if ($startMinutes === null) {
            return $empty;
        }

        $durationMin = $durationSeconds / 60;
        $endMinutes = $startMinutes + (int) ceil($durationMin);

        $segments = [];
        $cursor = (int) (floor($startMinutes / 60) * 60);
        $offset = 0.0;

        while ($cursor < $endMinutes) {
            $segEndMin = min($cursor + 60, $endMinutes);
            $segStartOffset = max(0, ($cursor - $startMinutes) * 60);
            $segEndOffset = min($durationSeconds, ($segEndMin - $startMinutes) * 60);

            if ($segEndOffset > $segStartOffset) {
                $hh = str_pad((string) intdiv($cursor, 60), 2, '0', STR_PAD_LEFT);
                $mm = str_pad((string) ($cursor % 60), 2, '0', STR_PAD_LEFT);
                $match = $this->lookup->match($dateYmd, $hh . $mm);

                $segments[] = [
                    'start_offset' => round($segStartOffset, 1),
                    'end_offset'   => round($segEndOffset, 1),
                    'show_id'      => $match['show_id'] ?? null,
                    'show_abbr'    => $match['show_abbr'] ?? null,
                    'title'        => $match['title'] ?? 'Unknown',
                ];
            }

            $cursor += 60;
            $offset += 3600;
        }

        $needsSplit = count($segments) > 1 || $durationSeconds >= self::SPLIT_THRESHOLD_SECONDS;

        if (!$needsSplit || $segments === []) {
            return $empty;
        }

        $lines = ['Schedule-based split suggestion (if timestamp is correct):'];
        foreach ($segments as $seg) {
            $label = $seg['show_abbr'] ?? $seg['title'];
            $lines[] = sprintf(
                '  %s–%s → %s',
                self::formatOffset($seg['start_offset']),
                self::formatOffset($seg['end_offset']),
                $label
            );
        }

        return [
            'needs_split' => true,
            'segments'    => $segments,
            'notes'       => implode("\n", $lines),
            'signals'     => ['split:schedule hourly blocks (' . count($segments) . ' segments)'],
        ];
    }

    private static function formatOffset(float $seconds): string
    {
        $m = (int) floor($seconds / 60);
        $s = (int) round($seconds - ($m * 60));

        return sprintf('%d:%02d', $m, $s);
    }
}
