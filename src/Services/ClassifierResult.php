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

    /**
     * @param list<string> $signals
     */
    public function withAdjustments(
        string $confidence,
        array $signals,
        ?int $showId = null,
        ?string $showAbbreviation = null,
        ?string $proposedDir = null,
        ?string $proposedFilename = null,
        ?string $fileDate = null,
        ?string $fileTime = null,
        bool $overrideDateTime = false,
        ?int $mediaTypeId = null,
        ?string $mediaTypeName = null,
        ?string $mediaTypeAbbreviation = null,
        bool $overrideMediaType = false,
    ): self {
        return new self(
            showId: $showId ?? $this->showId,
            showAbbreviation: $showAbbreviation ?? $this->showAbbreviation,
            mediaTypeId: $overrideMediaType ? $mediaTypeId : ($mediaTypeId ?? $this->mediaTypeId),
            mediaTypeName: $overrideMediaType ? $mediaTypeName : ($mediaTypeName ?? $this->mediaTypeName),
            mediaTypeAbbreviation: $overrideMediaType
                ? $mediaTypeAbbreviation
                : ($mediaTypeAbbreviation ?? $this->mediaTypeAbbreviation),
            fileDate: $overrideDateTime ? $fileDate : ($fileDate ?? $this->fileDate),
            fileTime: $overrideDateTime ? $fileTime : ($fileTime ?? $this->fileTime),
            proposedDir: $proposedDir ?? $this->proposedDir,
            proposedFilename: $proposedFilename ?? $this->proposedFilename,
            confidence: $confidence,
            signals: $signals,
            needsSplit: $this->needsSplit,
            splitNotes: $this->splitNotes,
            policyExactMatch: $this->policyExactMatch,
            sidecars: $this->sidecars,
            guestName: $this->guestName,
        );
    }
}
