<?php

declare(strict_types=1);

namespace MediaManager\Support;

/**
 * Reads app version and changelog from repo-root VERSION / CHANGELOG.md.
 */
final class AppVersion
{
    private static ?string $current = null;

    /** @var list<array{version: string, date: string, body: string}>|null */
    private static ?array $entries = null;

    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $path = self::rootPath() . '/VERSION';
        if (!is_readable($path)) {
            return self::$current = '0.0.0';
        }

        $raw = trim((string) file_get_contents($path));
        if ($raw === '' || preg_match('/^\d+\.\d+\.\d+/', $raw) !== 1) {
            return self::$current = '0.0.0';
        }

        return self::$current = $raw;
    }

    /**
     * @return list<array{version: string, date: string, body: string}>
     */
    public static function changelogEntries(int $limit = 20): array
    {
        if (self::$entries === null) {
            self::$entries = self::parseChangelog();
        }

        if ($limit < 1) {
            return [];
        }

        return array_slice(self::$entries, 0, $limit);
    }

    /**
     * Lightweight safe HTML for changelog section bodies (### headings + bullet lists).
     */
    public static function formatBodyHtml(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $html = [];
        $inList = false;

        $closeList = static function () use (&$html, &$inList): void {
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
        };

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^###\s+(.+)$/', $trimmed, $m) === 1) {
                $closeList();
                $html[] = '<h3 class="h6 mt-3 mb-2">' . View::e($m[1]) . '</h3>';
                continue;
            }

            if (preg_match('/^##\s+(.+)$/', $trimmed, $m) === 1) {
                $closeList();
                $html[] = '<h3 class="h6 mt-3 mb-2">' . View::e($m[1]) . '</h3>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m) === 1) {
                if (!$inList) {
                    $html[] = '<ul class="mb-2 ps-3">';
                    $inList = true;
                }
                $html[] = '<li class="mb-1">' . View::e($m[1]) . '</li>';
                continue;
            }

            if (trim($trimmed) === '') {
                $closeList();
                continue;
            }

            $closeList();
            $html[] = '<p class="mb-2 text-soft">' . View::e($trimmed) . '</p>';
        }

        $closeList();

        return $html === [] ? '<p class="mb-0 text-soft">No notes.</p>' : implode("\n", $html);
    }

    /** @return list<array{version: string, date: string, body: string}> */
    private static function parseChangelog(): array
    {
        $path = self::rootPath() . '/CHANGELOG.md';
        if (!is_readable($path)) {
            return [];
        }

        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return [];
        }

        // Split on ## [x.y.z] — YYYY-MM-DD (em dash or hyphen)
        if (preg_match_all(
            '/^##\s*\[(\d+\.\d+\.\d+)\]\s*[—\-]\s*(\d{4}-\d{2}-\d{2})\s*$/m',
            $raw,
            $matches,
            PREG_OFFSET_CAPTURE
        ) === 0 || $matches[0] === []) {
            return [];
        }

        $entries = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $headerOffset = (int) $matches[0][$i][1];
            $headerLen    = strlen($matches[0][$i][0]);
            $bodyStart    = $headerOffset + $headerLen;
            $bodyEnd      = $i + 1 < $count
                ? (int) $matches[0][$i + 1][1]
                : strlen($raw);
            $body = trim(substr($raw, $bodyStart, $bodyEnd - $bodyStart));

            $entries[] = [
                'version' => $matches[1][$i][0],
                'date'    => $matches[2][$i][0],
                'body'    => $body,
            ];
        }

        return $entries;
    }

    private static function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }
}
