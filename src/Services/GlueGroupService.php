<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;

/**
 * Applies multipart glue flags after scan / reclassify, and supports manual groups.
 */
final class GlueGroupService
{
    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
    ) {
    }

    /** @return int Number of files updated */
    public function applyForScanJob(int $scanJobId): int
    {
        $dirs = $this->files->distinctOriginalDirsForScanJob($scanJobId);
        if ($dirs === []) {
            return 0;
        }

        $rows = $this->files->listByOriginalDirs($dirs);
        if ($rows === []) {
            return 0;
        }

        return $this->applyAutoFlags($rows);
    }

    /**
     * @param list<array{id: int, original_path: string, glue_group_key?: ?string}> $rows
     * @return int Number of files updated
     */
    public function applyAutoFlags(array $rows): int
    {
        $paths = [];
        foreach ($rows as $row) {
            $path = str_replace('\\', '/', (string) ($row['original_path'] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        $detected = GlueGroupDetector::analyze($paths);
        $updated = 0;

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $existingKey = isset($row['glue_group_key']) ? (string) $row['glue_group_key'] : '';
            if (GlueGroupDetector::isManualGroupKey($existingKey !== '' ? $existingKey : null)) {
                continue;
            }

            $path = str_replace('\\', '/', (string) ($row['original_path'] ?? ''));
            $info = $detected[$path] ?? null;
            if ($info !== null) {
                $ok = $this->files->updateGlueFlag(
                    $id,
                    true,
                    $info['glue_group_key'],
                    $info['glue_part_index'],
                    $info['glue_notes']
                );
            } else {
                $ok = $this->files->updateGlueFlag($id, false, null, null, '');
            }
            if ($ok) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param list<int> $fileIds
     * @return array{ok: bool, count: int, message: string}
     */
    public function markManualGroup(array $fileIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn (int $id): bool => $id > 0
        )));
        if (count($ids) < 2) {
            return ['ok' => false, 'count' => 0, 'message' => 'Select at least two files to mark as a glue group.'];
        }

        $files = [];
        foreach ($ids as $id) {
            $row = $this->files->findById($id);
            if ($row === null) {
                continue;
            }
            if (!in_array((string) ($row['status'] ?? ''), ['PENDING', 'FLAGGED', 'REJECTED', 'APPROVED'], true)) {
                continue;
            }
            $files[] = [
                'id'                => (int) $row['id'],
                'original_path'     => (string) $row['original_path'],
                'original_filename' => (string) ($row['original_filename'] ?? ''),
            ];
        }

        $group = GlueGroupDetector::buildManualGroup($files);
        if ($group === null) {
            return ['ok' => false, 'count' => 0, 'message' => 'Could not build a glue group from the selection.'];
        }

        $count = 0;
        foreach ($group['members'] as $member) {
            if ($this->files->updateGlueFlag(
                $member['id'],
                true,
                $group['glue_group_key'],
                $member['glue_part_index'],
                $member['glue_notes']
            )) {
                $count++;
            }
        }

        return [
            'ok'      => $count >= 2,
            'count'   => $count,
            'message' => $count . ' file(s) marked as a glue group.',
        ];
    }

    /**
     * @param list<int> $fileIds
     * @return int Cleared count
     */
    public function clearGlue(array $fileIds): int
    {
        $count = 0;
        foreach ($fileIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $file = $this->files->findById($id);
            if ($file === null || empty($file['needs_glue'])) {
                continue;
            }
            if (!in_array((string) ($file['status'] ?? ''), ['PENDING', 'FLAGGED', 'REJECTED', 'APPROVED'], true)) {
                continue;
            }
            if ($this->files->updateGlueFlag($id, false, null, null, '')) {
                $count++;
            }
        }

        return $count;
    }
}
