<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Database;

/**
 * Wipe workflow data (scan/catalog/shows/timeline/etc). Preserves users and system config.
 */
final class DatabaseWipeService
{
    /** Tables wiped, in FK-safe order (children first). */
    private const WIPE_TABLES = [
        'glue_queue',
        'caption_extract_jobs',
        'split_audio_jobs',
        'thumbnail_jobs',
        'split_queue',
        'schedule_expected_gaps',
        'files',
        'scan_jobs',
        'program_schedule_entries',
        'legacy_rename_map',
        'conversion_rules',
        'scan_ignore_paths',
        'shows',
        'continuity_check_log',
        'audit_log',
        'auth_attempts',
    ];

    /**
     * @return array{
     *   tables: list<string>,
     *   media_files: int,
     *   thumbnail_files: int,
     *   preview_files: int,
     *   split_proxy_files: int
     * }
     */
    public function wipe(string $projectRoot): array
    {
        $pdo = Database::connection();
        $wiped = [];
        foreach (self::WIPE_TABLES as $table) {
            if ($this->tableExists($pdo, $table)) {
                $wiped[] = $table;
            }
        }

        if ($wiped === []) {
            throw new \RuntimeException('No wipeable tables found.');
        }

        // Single TRUNCATE so FKs between wiped tables resolve together.
        $quoted = array_map(static fn (string $t): string => '"' . str_replace('"', '""', $t) . '"', $wiped);
        $pdo->exec('TRUNCATE TABLE ' . implode(', ', $quoted) . ' RESTART IDENTITY CASCADE');

        $mediaDir = $projectRoot . '/' . trim((string) env('STORAGE_MEDIA', 'storage/media'), '/');
        $thumbDir = $projectRoot . '/' . trim((string) env('STORAGE_THUMBNAILS', 'storage/thumbnails'), '/');
        $prevDir  = $projectRoot . '/' . trim((string) env('STORAGE_PREVIEWS', 'storage/previews'), '/');
        $splitDir = $projectRoot . '/' . trim((string) env('STORAGE_SPLIT_PROXY', 'storage/split-proxy'), '/');

        return [
            'tables'            => $wiped,
            'media_files'       => $this->clearDirectory($mediaDir),
            'thumbnail_files'   => $this->clearDirectory($thumbDir),
            'preview_files'     => $this->clearDirectory($prevDir),
            'split_proxy_files' => $this->clearDirectory($splitDir),
        ];
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = current_schema() AND table_name = ? LIMIT 1'
        );
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    private function clearDirectory(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $path = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                @rmdir($path);
                continue;
            }
            // Keep .gitkeep placeholders
            if ($fileInfo->getFilename() === '.gitkeep') {
                continue;
            }
            if (@unlink($path)) {
                $count++;
            }
        }

        return $count;
    }
}
