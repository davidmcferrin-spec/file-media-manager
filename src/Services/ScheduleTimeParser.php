<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Parse schedule CSV time slots, days, and expand to hourly blocks.
 */
final class ScheduleTimeParser
{
    public const DOW_MON = 1;
    public const DOW_TUE = 2;
    public const DOW_WED = 4;
    public const DOW_THU = 8;
    public const DOW_FRI = 16;
    public const DOW_SAT = 32;
    public const DOW_SUN = 64;

    /** @return array{start: int, end: int}|null minutes from midnight */
    public static function parseTimeSlot(string $slot): ?array
    {
        $slot = trim(str_replace(['–', '—'], '-', $slot));
        if (preg_match(
            '/(\d{1,2}):(\d{2})\s*(AM|PM)\s*-\s*(\d{1,2}):(\d{2})\s*(AM|PM)/i',
            $slot,
            $m
        ) !== 1) {
            return null;
        }

        $start = self::toMinutes((int) $m[1], (int) $m[2], strtoupper($m[3]));
        $end   = self::toMinutes((int) $m[4], (int) $m[5], strtoupper($m[6]));
        if ($start === null || $end === null) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * True when slot spills past midnight into the next calendar day (skip import).
     * 11 PM – 12 AM is allowed (same broadcast day).
     */
    public static function isOvernightSpill(int $startMinutes, int $endMinutes): bool
    {
        if ($endMinutes === 0 && $startMinutes > 0) {
            return false;
        }

        return $endMinutes > 0 && $endMinutes <= $startMinutes;
    }

    /** @return list<array{start: int, end: int}> hourly blocks in minutes from midnight */
    public static function expandToHourlyBlocks(int $startMinutes, int $endMinutes): array
    {
        if ($endMinutes === 0 && $startMinutes > 0) {
            $endMinutes = 24 * 60;
        }
        if ($endMinutes <= $startMinutes) {
            return [];
        }

        $blocks = [];
        $cursor = (int) (floor($startMinutes / 60) * 60);

        while ($cursor < $endMinutes) {
            $next = $cursor + 60;
            if ($next > $startMinutes && $cursor < $endMinutes) {
                $blocks[] = ['start' => $cursor, 'end' => min($next, $endMinutes)];
            }
            $cursor = $next;
        }

        return $blocks;
    }

    public static function minutesToTime(int $minutes, bool $asEnd = false): string
    {
        if ($asEnd && $minutes >= 24 * 60) {
            return '00:00:00';
        }
        $minutes = max(0, min($minutes, 24 * 60 - 1));
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%02d:%02d:00', $h, $m);
    }

    /** @return int bitmask Mon=1 … Sun=64 */
    public static function parseDays(string $days): int
    {
        $days = trim(str_replace(['–', '—'], '-', $days));
        $map = [
            'mon' => self::DOW_MON,
            'tue' => self::DOW_TUE,
            'wed' => self::DOW_WED,
            'thu' => self::DOW_THU,
            'fri' => self::DOW_FRI,
            'sat' => self::DOW_SAT,
            'sun' => self::DOW_SUN,
        ];

        $mask = 0;
        $lower = strtolower($days);

        if ($lower === '') {
            return self::DOW_MON | self::DOW_TUE | self::DOW_WED | self::DOW_THU | self::DOW_FRI;
        }

        if (str_contains($lower, 'mon-fri') || str_contains($lower, 'mon–fri')) {
            return self::DOW_MON | self::DOW_TUE | self::DOW_WED | self::DOW_THU | self::DOW_FRI;
        }
        if (str_contains($lower, 'sat-sun') || str_contains($lower, 'sat–sun')) {
            return self::DOW_SAT | self::DOW_SUN;
        }

        foreach ($map as $key => $bit) {
            if (str_contains($lower, $key)) {
                $mask |= $bit;
            }
        }

        return $mask !== 0 ? $mask : (self::DOW_MON | self::DOW_TUE | self::DOW_WED | self::DOW_THU | self::DOW_FRI);
    }

    public static function dayBitFromDate(string $yyyymmdd): int
    {
        $dt = \DateTimeImmutable::createFromFormat('Ymd', $yyyymmdd, new \DateTimeZone('America/New_York'));
        if ($dt === false) {
            return 0;
        }
        $dow = (int) $dt->format('N');

        return match ($dow) {
            1 => self::DOW_MON,
            2 => self::DOW_TUE,
            3 => self::DOW_WED,
            4 => self::DOW_THU,
            5 => self::DOW_FRI,
            6 => self::DOW_SAT,
            7 => self::DOW_SUN,
            default => 0,
        };
    }

    public static function isReplayNotes(string $notes): bool
    {
        return preg_match('/re-?\s*air/i', $notes) === 1;
    }

    private static function toMinutes(int $hour, int $minute, string $ampm): ?int
    {
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }
        if ($ampm === 'AM') {
            if ($hour === 12) {
                $hour = 0;
            }
        } elseif ($hour !== 12) {
            $hour += 12;
        }

        return $hour * 60 + $minute;
    }
}
