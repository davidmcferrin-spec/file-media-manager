<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Detects multipart media sets that should be concatenated (glued):
 *   ShowName.ext, ShowName_1.ext, ShowName_2.ext, …
 */
final class GlueGroupDetector
{
    /**
     * @param list<string> $paths Absolute or normalized paths
     * @return array<string, array{
     *   needs_glue: bool,
     *   glue_group_key: string,
     *   glue_part_index: int,
     *   glue_notes: string,
     *   part_count: int
     * }>
     */
    public static function analyze(array $paths): array
    {
        /** @var array<string, list<array{path: string, part: int, name: string}>> $buckets */
        $buckets = [];

        foreach ($paths as $path) {
            $path = str_replace('\\', '/', (string) $path);
            if ($path === '') {
                continue;
            }
            $parsed = self::parsePath($path);
            if ($parsed === null) {
                continue;
            }
            $key = self::autoGroupKey($parsed['dir'], $parsed['base'], $parsed['ext']);
            $buckets[$key][] = [
                'path' => $path,
                'part' => $parsed['part'],
                'name' => $parsed['filename'],
            ];
        }

        $out = [];
        foreach ($buckets as $key => $members) {
            if (count($members) < 2) {
                continue;
            }
            usort($members, static function (array $a, array $b): int {
                if ($a['part'] !== $b['part']) {
                    return $a['part'] <=> $b['part'];
                }

                return strcasecmp($a['name'], $b['name']);
            });
            $count = count($members);
            $notes = sprintf('%d parts — concat with ffmpeg before final rename', $count);
            foreach ($members as $member) {
                $out[$member['path']] = [
                    'needs_glue'      => true,
                    'glue_group_key'  => $key,
                    'glue_part_index' => $member['part'],
                    'glue_notes'      => $notes,
                    'part_count'      => $count,
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array{id: int, original_path: string, original_filename?: string}> $files
     * @return array{
     *   glue_group_key: string,
     *   members: list<array{id: int, glue_part_index: int, glue_notes: string}>
     * }|null
     */
    public static function buildManualGroup(array $files): ?array
    {
        if (count($files) < 2) {
            return null;
        }

        $sorted = $files;
        usort($sorted, static function (array $a, array $b): int {
            $pathA = str_replace('\\', '/', (string) ($a['original_path'] ?? ''));
            $pathB = str_replace('\\', '/', (string) ($b['original_path'] ?? ''));
            $partA = self::parsePath($pathA)['part'] ?? 0;
            $partB = self::parsePath($pathB)['part'] ?? 0;
            if ($partA !== $partB) {
                return $partA <=> $partB;
            }
            $nameA = (string) ($a['original_filename'] ?? basename($pathA));
            $nameB = (string) ($b['original_filename'] ?? basename($pathB));

            return strcasecmp($nameA, $nameB);
        });

        $ids = array_map(static fn (array $f): int => (int) $f['id'], $sorted);
        sort($ids);
        $groupKey = 'manual:' . sha1(implode(',', $ids));
        $count = count($sorted);
        $notes = sprintf('%d parts — manual glue group', $count);

        $members = [];
        foreach ($sorted as $i => $file) {
            $path = str_replace('\\', '/', (string) ($file['original_path'] ?? ''));
            $parsed = self::parsePath($path);
            $partIndex = $parsed['part'] ?? $i;
            $members[] = [
                'id'              => (int) $file['id'],
                'glue_part_index' => $partIndex,
                'glue_notes'      => $notes,
            ];
        }

        return [
            'glue_group_key' => $groupKey,
            'members'        => $members,
        ];
    }

    /**
     * @return array{dir: string, base: string, ext: string, part: int, filename: string}|null
     */
    public static function parsePath(string $path): ?array
    {
        $path = str_replace('\\', '/', $path);
        $filename = basename($path);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }
        $dir = str_replace('\\', '/', dirname($path));
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($stem === '') {
            return null;
        }

        $part = 0;
        $base = $stem;
        if (preg_match('/^(.+)_(\d+)$/', $stem, $m) === 1) {
            $base = $m[1];
            $part = (int) $m[2];
        }

        return [
            'dir'      => $dir,
            'base'     => $base,
            'ext'      => $ext,
            'part'     => $part,
            'filename' => $filename,
        ];
    }

    public static function autoGroupKey(string $dir, string $base, string $ext): string
    {
        $dir = str_replace('\\', '/', rtrim($dir, '/'));
        $base = strtolower(trim($base));
        $ext = strtolower(trim($ext));

        return 'auto:' . hash('sha256', $dir . '|' . $base . '|' . $ext);
    }

    public static function isManualGroupKey(?string $key): bool
    {
        return is_string($key) && str_starts_with($key, 'manual:');
    }
}
