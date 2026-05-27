<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Parse policy-style proposed paths and filenames.
 */
final class ProposalFilenameParser
{
    /** @return array{show_abbr: ?string, date: ?string, time: ?string, media_token: ?string, guest: ?string} */
    public static function parseFilename(string $filename): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match(
            '/^([A-Z0-9]+)_(\d{8})_(\d{4})_GISO_(.+)$/i',
            $base,
            $m
        ) === 1) {
            return [
                'show_abbr'   => strtoupper($m[1]),
                'date'        => $m[2],
                'time'        => $m[3],
                'media_token' => 'GISO',
                'guest'       => $m[4],
            ];
        }
        if (preg_match(
            '/^([A-Z0-9]+)_(\d{8})_(\d{4})_([A-Z0-9]+)$/i',
            $base,
            $m
        ) === 1) {
            return [
                'show_abbr'   => strtoupper($m[1]),
                'date'        => $m[2],
                'time'        => $m[3],
                'media_token' => strtoupper($m[4]),
                'guest'       => null,
            ];
        }

        return [
            'show_abbr'   => null,
            'date'        => null,
            'time'        => null,
            'media_token' => null,
            'guest'       => null,
        ];
    }

    /** @return array{show_abbr: ?string, year: ?string, month: ?string, folder_type: ?string} */
    public static function parseDir(string $dir): array
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        $parts = $dir !== '' ? explode('/', $dir) : [];
        if (count($parts) < 4) {
            return ['show_abbr' => null, 'year' => null, 'month' => null, 'folder_type' => null];
        }

        return [
            'show_abbr'   => strtoupper($parts[0]),
            'year'        => $parts[1],
            'month'       => $parts[2],
            'folder_type' => $parts[3],
        ];
    }

    public static function isTemplate(string $path, string $filename): bool
    {
        $haystack = strtoupper($path . ' ' . $filename);

        return str_contains($haystack, 'YYYY')
            || str_contains($haystack, 'YYYYMMDD')
            || str_contains($haystack, 'HHMM');
    }

    public static function normalizePath(string $path): string
    {
        return strtolower(trim(str_replace('\\', '/', $path), '/'));
    }

    public static function buildFullPath(string $dir, string $filename): string
    {
        return self::normalizePath(rtrim($dir, '/') . '/' . $filename);
    }
}
