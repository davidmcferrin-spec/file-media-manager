<?php

declare(strict_types=1);

namespace MediaManager\Support;

/**
 * Shared parsers for program_schedule_entries form/POST payloads.
 */
final class ScheduleEntryParser
{
    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    public static function parse(array $post, ?int $forceShowId = null): ?array
    {
        $showId = $forceShowId ?? (int) ($post['show_id'] ?? 0);
        $title  = trim((string) ($post['title'] ?? ''));
        if ($showId <= 0 || $title === '') {
            return null;
        }

        $hourStart = self::normalizeTime((string) ($post['hour_start_et'] ?? ''));
        $hourEnd   = self::normalizeTime((string) ($post['hour_end_et'] ?? ''));
        if ($hourStart === null || $hourEnd === null) {
            return null;
        }

        $daysRaw = $post['days'] ?? [];
        if (!is_array($daysRaw)) {
            $daysRaw = [];
        }
        $dayBits = [1, 2, 4, 8, 16, 32, 64];
        $mask    = 0;
        foreach ($daysRaw as $bit) {
            $bit = (int) $bit;
            if (in_array($bit, $dayBits, true)) {
                $mask |= $bit;
            }
        }
        if ($mask === 0) {
            return null;
        }

        $from = trim((string) ($post['effective_from'] ?? ''));
        $to   = trim((string) ($post['effective_to'] ?? ''));
        if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            return null;
        }
        if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return null;
        }

        $eraId = isset($post['broadcast_era_id']) ? (int) $post['broadcast_era_id'] : 0;

        return [
            'show_id'          => $showId,
            'title'            => $title,
            'hour_start_et'    => $hourStart,
            'hour_end_et'      => $hourEnd,
            'days_of_week'     => $mask,
            'effective_from'   => $from,
            'effective_to'     => $to !== '' ? $to : null,
            'era_name'         => trim((string) ($post['era_name'] ?? '')),
            'anchor_names'     => trim((string) ($post['anchor_names'] ?? '')),
            'show_type'        => trim((string) ($post['show_type'] ?? '')),
            'network_brand'    => trim((string) ($post['network_brand'] ?? '')),
            'notes'            => trim((string) ($post['notes'] ?? '')),
            'active'           => isset($post['active']),
            'broadcast_era_id' => $eraId > 0 ? $eraId : null,
        ];
    }

    public static function normalizeTime(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $input, $m) === 1) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d:00', $h, $min);
            }
        }

        return null;
    }

    /** @return list<array{bit: int, label: string}> */
    public static function dayOptions(): array
    {
        return [
            ['bit' => 1, 'label' => 'Mon'],
            ['bit' => 2, 'label' => 'Tue'],
            ['bit' => 4, 'label' => 'Wed'],
            ['bit' => 8, 'label' => 'Thu'],
            ['bit' => 16, 'label' => 'Fri'],
            ['bit' => 32, 'label' => 'Sat'],
            ['bit' => 64, 'label' => 'Sun'],
        ];
    }

    public static function daysLabel(int $mask): string
    {
        $parts = [];
        foreach (self::dayOptions() as $opt) {
            if (($mask & $opt['bit']) !== 0) {
                $parts[] = $opt['label'];
            }
        }

        return $parts === [] ? '—' : implode(' ', $parts);
    }
}
