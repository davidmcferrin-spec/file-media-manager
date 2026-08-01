<?php

declare(strict_types=1);

namespace MediaManager\Services;

final class MediaExtensions
{
    /** @var list<string> */
    public const MEDIA = [
        'mp4', 'mxf', 'mov', 'ts', 'm2ts', 'mpg', 'mpeg', 'avi', 'mkv', 'wmv', 'm4v',
    ];

    /** @var list<string> */
    public const SIDECAR = [
        'xml', 'scc', 'json', 'xls', 'xlsx', 'csv', 'txt', 'pdf', 'cue', 'cca', 'stl',
        'srt', 'vtt',
    ];

    public static function isMedia(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::MEDIA, true);
    }

    public static function isSidecar(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::SIDECAR, true);
    }

    public static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
