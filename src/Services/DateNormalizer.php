<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Extract and normalize YYYYMMDD / HHMM from legacy filenames and metadata.
 */
final class DateNormalizer
{
    /**
     * @param list<string> $pathSegments Directory segments (for year hints like "JUNE 2025")
     * @return array{date: ?string, time: ?string, signal: ?string}
     */
    public static function fromFilename(string $filename, array $pathSegments = []): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return self::fromToken($base, self::yearHintFromPath($pathSegments));
    }

    /**
     * Parse date/time tokens from a filename stem or path segment.
     *
     * @return array{date: ?string, time: ?string, signal: ?string}
     */
    public static function fromToken(string $base, ?int $yearHint = null): array
    {
        $base = trim($base);
        if ($base === '') {
            return ['date' => null, 'time' => null, 'signal' => null];
        }

        // Seagate / linear PGM feed: MMDDYY 8P EST · 060625 7A EST · 121224 12P EST
        $seagate = self::parseMmddyyHourAp($base, $yearHint);
        if ($seagate['date'] !== null) {
            return $seagate;
        }

        // Primary: _YYYYMMDD_HHMM or space/hyphen separators (time 4–8 digits).
        if (preg_match('/(?:^|[^0-9])(\d{8})[_\-\s]+(\d{4,8})(?:[^0-9]|$)/', $base, $m) === 1) {
            $time = self::normalizeTime($m[2]);
            if (self::isValidDate($m[1]) && $time !== null) {
                return [
                    'date'   => $m[1],
                    'time'   => $time,
                    'signal' => 'filename:YYYYMMDD_HHMM',
                ];
            }
        }

        // Contiguous YYYYMMDDHHMM (12 digits) — common in encoder dumps.
        if (preg_match('/(?:^|[^0-9])(\d{8})(\d{4})(?:\d{0,4})?(?:[^0-9]|$)/', $base, $m) === 1) {
            $time = self::normalizeTime($m[2]);
            if (self::isValidDate($m[1]) && $time !== null) {
                return [
                    'date'   => $m[1],
                    'time'   => $time,
                    'signal' => 'filename:YYYYMMDDHHMM',
                ];
            }
        }

        // ISO date with optional time: 2022-10-03_1850 / 2022-10-03 18:50 / 2022.10.03-1850
        if (preg_match(
            '/(\d{4})[-.\/](\d{2})[-.\/](\d{2})(?:[_\-\sT]+(\d{1,2})[:.]?(\d{2})(?:[:.]?\d{2})?)?/',
            $base,
            $m
        ) === 1) {
            $date = $m[1] . $m[2] . $m[3];
            if (self::isValidDate($date)) {
                $time = null;
                if (isset($m[4], $m[5]) && $m[4] !== '' && $m[5] !== '') {
                    $time = self::normalizeTime(sprintf('%02d%s', (int) $m[4], $m[5]));
                }

                return [
                    'date'   => $date,
                    'time'   => $time,
                    'signal' => $time !== null ? 'filename:YYYY-MM-DD_HHMM' : 'filename:YYYY-MM-DD',
                ];
            }
        }

        // US-style MM-DD-YYYY or MM/DD/YYYY with optional time.
        if (preg_match(
            '/(?:^|[^0-9])(\d{1,2})[-.\/](\d{1,2})[-.\/](\d{4})(?:[_\-\s]+(\d{1,2})[:.]?(\d{2}))?/',
            $base,
            $m
        ) === 1) {
            $date = sprintf('%04d%02d%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
            if (self::isValidDate($date)) {
                $time = null;
                if (isset($m[4], $m[5]) && $m[4] !== '' && $m[5] !== '') {
                    $time = self::normalizeTime(sprintf('%02d%s', (int) $m[4], $m[5]));
                }

                return [
                    'date'   => $date,
                    'time'   => $time,
                    'signal' => $time !== null ? 'filename:MM-DD-YYYY_HHMM' : 'filename:MM-DD-YYYY',
                ];
            }
        }

        // Standalone 8-digit date (no time).
        if (preg_match('/(?:^|[^0-9])(\d{8})(?:[^0-9]|$)/', $base, $m) === 1) {
            if (self::isValidDate($m[1])) {
                return [
                    'date'   => $m[1],
                    'time'   => null,
                    'signal' => 'filename:YYYYMMDD',
                ];
            }
        }

        // Time alone near broadcast-looking HHMM (only when preceded by show-ish tokens) — skip;
        // bare times are too ambiguous without a date.

        return ['date' => null, 'time' => null, 'signal' => null];
    }

    /**
     * Extract date (and optional time) from path folders: …/YYYY/MM/DD/… or …/YYYY/MM/….
     *
     * @param list<string> $segments Directory segments (filename already removed)
     * @return array{date: ?string, time: ?string, signal: ?string}
     */
    public static function fromPathSegments(array $segments): array
    {
        $n = count($segments);
        $yearHint = self::yearHintFromPath($segments);
        for ($i = 0; $i < $n; $i++) {
            $token = (string) $segments[$i];
            $parsed = self::fromToken($token, $yearHint);
            if ($parsed['date'] !== null) {
                return [
                    'date'   => $parsed['date'],
                    'time'   => $parsed['time'],
                    'signal' => 'path:' . ($parsed['signal'] ?? 'token'),
                ];
            }
        }

        // …/YYYY/MM/DD/…
        for ($i = 0; $i <= $n - 3; $i++) {
            $year = (string) ($segments[$i] ?? '');
            $month = (string) ($segments[$i + 1] ?? '');
            $day = (string) ($segments[$i + 2] ?? '');
            if (
                preg_match('/^\d{4}$/', $year) === 1
                && preg_match('/^\d{2}$/', $month) === 1
                && preg_match('/^\d{2}$/', $day) === 1
            ) {
                $date = $year . $month . $day;
                if (self::isValidDate($date)) {
                    return [
                        'date'   => $date,
                        'time'   => null,
                        'signal' => 'path:YYYY/MM/DD',
                    ];
                }
            }
        }

        // …/YYYY/MM/… (day defaulted to 01)
        for ($i = 0; $i <= $n - 2; $i++) {
            $year = (string) ($segments[$i] ?? '');
            $month = (string) ($segments[$i + 1] ?? '');
            if (preg_match('/^\d{4}$/', $year) === 1 && preg_match('/^\d{2}$/', $month) === 1) {
                $date = $year . $month . '01';
                if (self::isValidDate($date)) {
                    return [
                        'date'   => $date,
                        'time'   => null,
                        'signal' => 'path:YYYY/MM (day defaulted to 01)',
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

    /**
     * MMDDYY + 12-hour clock with A/P (Eastern), optional EST suffix.
     * Example: "060625 8P EST" → 20250606 / 2000
     *
     * @return array{date: ?string, time: ?string, signal: ?string}
     */
    public static function parseMmddyyHourAp(string $base, ?int $yearHint = null): array
    {
        if (preg_match(
            '/(?:^|[^0-9])(\d{2})(\d{2})(\d{2})[_\s\-]+(\d{1,2})([AaPp])(?:\s*EST)?(?:[^A-Za-z0-9]|$)/',
            $base,
            $m
        ) !== 1) {
            return ['date' => null, 'time' => null, 'signal' => null];
        }

        $month = (int) $m[1];
        $day = (int) $m[2];
        $yy = (int) $m[3];
        $hour12 = (int) $m[4];
        $ap = strtoupper($m[5]);
        $year = self::expandTwoDigitYear($yy, $yearHint);
        $hhmm = self::hourApToHhmm($hour12, $ap);
        if ($hhmm === null) {
            return ['date' => null, 'time' => null, 'signal' => null];
        }

        $date = sprintf('%04d%02d%02d', $year, $month, $day);
        if (!self::isValidDate($date)) {
            return ['date' => null, 'time' => null, 'signal' => null];
        }

        return [
            'date'   => $date,
            'time'   => $hhmm,
            'signal' => 'filename:MMDDYY_H{A|P}_EST',
        ];
    }

    /** 12A→0000, 8A→0800, 12P→1200, 8P→2000. */
    public static function hourApToHhmm(int $hour12, string $amPm): ?string
    {
        if ($hour12 < 1 || $hour12 > 12) {
            return null;
        }
        $ap = strtoupper(substr(trim($amPm), 0, 1));
        if ($ap === 'A') {
            $h24 = $hour12 === 12 ? 0 : $hour12;
        } elseif ($ap === 'P') {
            $h24 = $hour12 === 12 ? 12 : $hour12 + 12;
        } else {
            return null;
        }

        return sprintf('%02d00', $h24);
    }

    /** Prefer path year (e.g. JUNE 2025); else 00–69 → 20xx, 70–99 → 19xx. */
    public static function expandTwoDigitYear(int $yy, ?int $yearHint = null): int
    {
        $yy = max(0, min(99, $yy));
        if ($yearHint !== null && $yearHint >= 1900 && $yearHint <= 2100) {
            $century = intdiv($yearHint, 100) * 100;
            $candidate = $century + $yy;
            // If hint is 2025 and yy is 25 → 2025; if yy is 24 near year boundary → 2024.
            if (abs($candidate - $yearHint) <= 1) {
                return $candidate;
            }

            return $candidate;
        }

        return $yy >= 70 ? 1900 + $yy : 2000 + $yy;
    }

    /**
     * Year from folders like "JUNE 2025", "DECEMBER 2024", or bare "2025".
     *
     * @param list<string> $segments
     */
    public static function yearHintFromPath(array $segments): ?int
    {
        $monthNames = 'January|February|March|April|May|June|July|August|September|October|November|December'
            . '|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';

        foreach (array_reverse($segments) as $seg) {
            $seg = trim((string) $seg);
            if ($seg === '') {
                continue;
            }
            if (preg_match('/\b(?:' . $monthNames . ')\s+(\d{4})\b/i', $seg, $m) === 1) {
                $y = (int) $m[1];
                if ($y >= 1990 && $y <= 2100) {
                    return $y;
                }
            }
            if (preg_match('/^(19|20)\d{2}$/', $seg) === 1) {
                return (int) $seg;
            }
        }

        // Parent ranges like "SEAGATE PGM FEED OCT 2024-JUNE 30, 2025" — prefer the latest year.
        foreach (array_reverse($segments) as $seg) {
            if (preg_match_all('/\b(20\d{2}|19\d{2})\b/', (string) $seg, $m) > 0) {
                $years = array_map('intval', $m[1]);
                $y = max($years);
                if ($y >= 1990 && $y <= 2100) {
                    return $y;
                }
            }
        }

        return null;
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

        $fromPath = self::fromPathSegments($segments);

        return $fromPath['date'];
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
