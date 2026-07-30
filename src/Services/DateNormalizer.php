<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Extract and normalize YYYYMMDD / HHMM from legacy filenames and metadata.
 */
final class DateNormalizer
{
    /** @return array{date: ?string, time: ?string, signal: ?string} */
    public static function fromFilename(string $filename): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        // Primary: _YYYYMMDD_HHMM or _YYYYMMDD_HHMMSS...
        if (preg_match('/_(\d{8})_(\d{4,8})(?:[^0-9]|$)/', $base, $m) === 1) {
            $time = self::normalizeTime($m[2]);
            if (self::isValidDate($m[1]) && $time !== null) {
                return [
                    'date'   => $m[1],
                    'time'   => $time,
                    'signal' => 'filename:_YYYYMMDD_HHMM',
                ];
            }
        }

        // Embedded: CLEAN2022-10-03 or 2022-10-03
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $base, $m) === 1) {
            $date = $m[1] . $m[2] . $m[3];
            if (self::isValidDate($date)) {
                return [
                    'date'   => $date,
                    'time'   => null,
                    'signal' => 'filename:YYYY-MM-DD',
                ];
            }
        }

        // Standalone 8-digit date
        if (preg_match('/(?:^|[^0-9])(\d{8})(?:[^0-9]|$)/', $base, $m) === 1) {
            if (self::isValidDate($m[1])) {
                return [
                    'date'   => $m[1],
                    'time'   => null,
                    'signal' => 'filename:YYYYMMDD',
                ];
            }
        }

        return ['date' => null, 'time' => null, 'signal' => null];
    }

    /** @return array{date: ?string, time: ?string, signal: ?string} */
    public static function fromPathSegments(array $segments): array
    {
        // Expect .../SHOW/YYYY/MM/TYPE/...
        if (count($segments) >= 3) {
            $year  = $segments[count($segments) - 3] ?? '';
            $month = $segments[count($segments) - 2] ?? '';
            if (preg_match('/^\d{4}$/', $year) === 1 && preg_match('/^\d{2}$/', $month) === 1) {
                $date = $year . $month . '01';
                if (self::isValidDate($date)) {
                    return [
                        'date'   => null,
                        'time'   => null,
                        'signal' => 'path:year/month hint only',
                    ];
                }
            }
        }

        return ['date' => null, 'time' => null, 'signal' => null];
    }

    /** @return array{date: ?string, time: ?string, signal: ?string} */
    public static function fromFfprobe(?string $creationTime): array
    {
        if ($creationTime === null || $creationTime === '') {
            return ['date' => null, 'time' => null, 'signal' => null];
        }

        try {
            $dt = new \DateTimeImmutable($creationTime);
            $dt = $dt->setTimezone(new \DateTimeZone('America/New_York'));
            $date = $dt->format('Ymd');
            $time = $dt->format('Hi');

            return [
                'date'   => $date,
                'time'   => $time,
                'signal' => 'ffprobe:creation_time',
            ];
        } catch (\Exception) {
            return ['date' => null, 'time' => null, 'signal' => null];
        }
    }

    public static function normalizeTime(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) < 4) {
            return null;
        }

        $hhmm = substr($digits, 0, 4);
        $hh   = (int) substr($hhmm, 0, 2);
        $mm   = (int) substr($hhmm, 2, 2);

        if ($hh > 23 || $mm > 59) {
            return null;
        }

        return $hhmm;
    }

    /** Minutes from midnight for an HHMM (or HH:MM) time string. */
    public static function timeToMinutes(?string $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $hhmm = self::normalizeTime($raw);
        if ($hhmm === null) {
            return null;
        }

        return ((int) substr($hhmm, 0, 2)) * 60 + (int) substr($hhmm, 2, 2);
    }

    public static function minutesToHhmm(int $minutes): string
    {
        $minutes = max(0, min($minutes, 24 * 60 - 1));

        return sprintf('%02d%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function isValidDate(string $yyyymmdd): bool
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $yyyymmdd, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Path segments YYYY and MM override filename date when filename date missing. */
    public static function mergePathDate(?string $date, array $segments): ?string
    {
        if ($date !== null) {
            return $date;
        }

        // segments relative: show/year/month/type/file
        if (count($segments) >= 3) {
            $yearIdx = count($segments) - 3;
            $year    = $segments[$yearIdx] ?? '';
            $month   = $segments[$yearIdx + 1] ?? '';
            $day     = '01';
            if (preg_match('/^\d{4}$/', $year) === 1 && preg_match('/^\d{2}$/', $month) === 1) {
                $candidate = $year . $month . $day;
                if (self::isValidDate($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public static function yearMonthFromPath(array $segments): ?array
    {
        if (count($segments) >= 3) {
            $yearIdx = count($segments) - 3;
            $year    = $segments[$yearIdx] ?? '';
            $month   = $segments[$yearIdx + 1] ?? '';
            if (preg_match('/^\d{4}$/', $year) === 1 && preg_match('/^\d{2}$/', $month) === 1) {
                return ['year' => $year, 'month' => $month];
            }
        }

        return null;
    }
}
