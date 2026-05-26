<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;

/**
 * Re-runs classifier heuristics to pre-fill the queue edit form.
 */
final class FileEditSuggester
{
    public function __construct(
        private readonly Classifier $classifier = new Classifier(),
    ) {
    }

    /** @param array<string, mixed> $file */
    /** @return array<string, mixed> */
    public function suggest(array $file): array
    {
        $mount = (string) ($file['source_mount'] ?? '');
        $sidecarPaths = [];
        foreach (FileRepository::parseSidecars($file['classifier_notes'] ?? null) as $sidecar) {
            $path = (string) ($sidecar['original_path'] ?? '');
            if ($path !== '') {
                $sidecarPaths[] = $path;
            }
        }

        $probe = null;
        if (!empty($file['duration_seconds']) || !empty($file['codec_video'])) {
            $probe = [
                'duration'      => $file['duration_seconds'] ?? null,
                'creation_time' => null,
            ];
        }

        try {
            $result = $this->classifier->classify(
                (string) $file['original_path'],
                $mount,
                $probe,
                $sidecarPaths
            );
        } catch (\Throwable) {
            return $this->emptySuggest();
        }

        return [
            'proposed_dir'      => $result->proposedDir ?: ($file['proposed_dir'] ?? null),
            'proposed_filename' => $result->proposedFilename ?: ($file['proposed_filename'] ?? null),
            'show_id'           => $result->showId ?: ($file['show_id'] ?? null),
            'media_type_id'     => $result->mediaTypeId ?: ($file['media_type_id'] ?? null),
            'file_date'         => $result->fileDate ?: ($file['file_date'] ?? null),
            'file_time'         => $result->fileTime ?: ($file['file_time'] ?? null),
            'show_abbr'         => $result->showAbbreviation,
            'media_type_name'   => $result->mediaTypeName,
            'confidence'        => $result->confidence,
            'signals'           => $result->signals,
        ];
    }

    /** @return array<string, mixed> */
    private function emptySuggest(): array
    {
        return [
            'proposed_dir'      => null,
            'proposed_filename' => null,
            'show_id'           => null,
            'media_type_id'     => null,
            'file_date'         => null,
            'file_time'         => null,
            'show_abbr'         => null,
            'media_type_name'   => null,
            'confidence'        => null,
            'signals'           => [],
        ];
    }
}
