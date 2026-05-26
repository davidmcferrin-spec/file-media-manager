<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\IgnorePathRepository;

final class ScanIgnore
{
    /** @var list<array{prefix: string, source_mount: ?string}> */
    private array $managedPrefixes;

    /**
     * @param list<array{prefix: string, source_mount: ?string}> $managedPrefixes
     */
    public function __construct(array $managedPrefixes = [])
    {
        $this->managedPrefixes = $managedPrefixes;
    }

    public static function fromRepository(?IgnorePathRepository $repo = null): self
    {
        $repo ??= new IgnorePathRepository();

        return new self($repo->activePrefixes());
    }

    /** @return list<string> */
    public static function builtInPatterns(): array
    {
        return [
            '/\/\.Trash\//i',
            '/\/_ShareBrowserVolumeUID_/i',
            '/\/summary-[^\/]+\.html$/i',
            '/\/\.[^\/]+$/',
        ];
    }

    public function shouldIgnore(string $path, ?string $sourceMount = null): bool
    {
        $path = $this->normalizePath($path);

        foreach (self::builtInPatterns() as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        if (strcasecmp(basename($path), 'README.txt') === 0) {
            return true;
        }

        return $this->matchesManagedPrefix($path, $sourceMount);
    }

    /** Skip directory traversal entirely when a folder is under an ignore prefix. */
    public function shouldIgnoreDirectory(string $dirPath, ?string $sourceMount = null): bool
    {
        return $this->matchesManagedPrefix($this->normalizePath($dirPath), $sourceMount);
    }

    private function matchesManagedPrefix(string $path, ?string $sourceMount): bool
    {
        $sourceMount = $sourceMount !== null ? rtrim(str_replace('\\', '/', $sourceMount), '/') : null;

        foreach ($this->managedPrefixes as $rule) {
            $prefix = $this->normalizePath($rule['prefix']);
            if ($prefix === '') {
                continue;
            }

            $ruleMount = isset($rule['source_mount']) && $rule['source_mount'] !== ''
                ? rtrim(str_replace('\\', '/', (string) $rule['source_mount']), '/')
                : null;

            if ($ruleMount !== null && $sourceMount !== null && $ruleMount !== $sourceMount) {
                continue;
            }

            if ($this->pathUnderPrefix($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function pathUnderPrefix(string $path, string $prefix): bool
    {
        if ($path === $prefix) {
            return true;
        }

        return str_starts_with($path, $prefix . '/');
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return rtrim($path, '/');
    }
}
