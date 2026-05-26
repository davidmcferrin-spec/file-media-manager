<?php

declare(strict_types=1);

namespace MediaManager\Services;

final class ClassifierResult
{
    /** @param list<string> $signals */
    /** @param list<array{original_path: string, proposed_filename: string}> $sidecars */
    public function __construct(
        public readonly ?int $showId,
        public readonly ?string $showAbbreviation,
        public readonly ?int $mediaTypeId,
        public readonly ?string $mediaTypeName,
        public readonly ?string $mediaTypeAbbreviation,
        public readonly ?string $fileDate,
        public readonly ?string $fileTime,
        public readonly ?string $proposedDir,
        public readonly ?string $proposedFilename,
        public readonly string $confidence,
        public readonly array $signals,
        public readonly bool $needsSplit,
        public readonly string $splitNotes,
        public readonly bool $policyExactMatch,
        public readonly array $sidecars = [],
        public readonly ?string $guestName = null,
    ) {
    }

    public function classifierNotesJson(): string
    {
        return json_encode([
            'signals'  => $this->signals,
            'sidecars' => $this->sidecars,
            'guest'    => $this->guestName,
            'exact'    => $this->policyExactMatch,
        ], JSON_THROW_ON_ERROR);
    }
}
