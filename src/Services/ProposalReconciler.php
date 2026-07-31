<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\ShowRepository;

final class ProposalReconciler
{
    public function __construct(
        private readonly MediaTypeResolver $mediaTypes = new MediaTypeResolver(),
        private readonly ShowRepository $shows = new ShowRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $mapRow normalized map row with target_dir, target_filename, etc.
     * @return array<string, mixed>
     */
    public function reconcile(array $file, ?array $mapRow): array
    {
        $classifierDir  = (string) ($file['classifier_proposed_dir'] ?? $file['proposed_dir'] ?? '');
        $classifierFile = (string) ($file['classifier_proposed_filename'] ?? $file['proposed_filename'] ?? '');
        $classifierConf = (string) ($file['classifier_confidence'] ?? $file['confidence'] ?? 'UNEVALUATED');

        $base = [
            'classifier_confidence'          => $classifierConf,
            'classifier_proposed_dir'        => $classifierDir !== '' ? $classifierDir : null,
            'classifier_proposed_filename'   => $classifierFile !== '' ? $classifierFile : null,
            'alt_proposed_dir'               => null,
            'alt_proposed_filename'          => null,
            'proposed_source'                => 'classifier',
            'alt_source'                     => null,
            'legacy_map_id'                  => null,
            'map_curator_confidence'         => null,
            'proposal_agreement'             => 'classifier_only',
            'proposed_dir'                   => $classifierDir !== '' ? $classifierDir : null,
            'proposed_filename'              => $classifierFile !== '' ? $classifierFile : null,
            'confidence'                     => $classifierConf,
            'status'                         => null,
            'reconcile_signals'              => [],
        ];

        if ($mapRow === null) {
            return $base;
        }

        $mapId = (int) ($mapRow['id'] ?? 0);
        $curatorScore = (int) ($mapRow['curator_confidence'] ?? 5);
        $rowType = (string) ($mapRow['row_type'] ?? 'concrete');
        $notes = (string) ($mapRow['notes'] ?? '');
        $signals = [];

        $base['legacy_map_id'] = $mapId > 0 ? $mapId : null;
        $base['map_curator_confidence'] = $curatorScore;

        if ($rowType === 'template') {
            $signals[] = 'map:template row — no literal target';
            $base['proposal_agreement'] = 'template';
            $base['confidence'] = 'LOW';
            $base['status'] = $this->shouldFlag($notes, $curatorScore) ? 'FLAGGED' : null;
            $base['reconcile_signals'] = $signals;
            if ($notes !== '') {
                $signals[] = 'map:notes ' . $notes;
            }

            return $base;
        }

        $mapDir  = (string) ($mapRow['target_dir'] ?? '');
        $mapFile = (string) ($mapRow['target_filename'] ?? '');
        if ($mapDir === '' || $mapFile === '') {
            $base['proposal_agreement'] = 'none';
            $base['reconcile_signals'] = ['map:concrete row missing target'];
            return $base;
        }

        $comparison = $this->compareProposals($classifierDir, $classifierFile, $mapDir, $mapFile, $mapRow);
        $agreement = $comparison['agreement'];
        $signals = array_merge($signals, $comparison['signals']);

        $base['alt_proposed_dir'] = $mapDir;
        $base['alt_proposed_filename'] = $mapFile;
        $base['alt_source'] = 'legacy_map';
        $base['proposal_agreement'] = $agreement;

        $recommendMap = $this->recommendMapPrimary($agreement, $curatorScore, $classifierDir, $mapDir);

        if ($recommendMap) {
            $base['proposed_dir'] = $mapDir;
            $base['proposed_filename'] = $mapFile;
            $base['proposed_source'] = 'legacy_map';
            $base['alt_proposed_dir'] = $classifierDir !== '' ? $classifierDir : null;
            $base['alt_proposed_filename'] = $classifierFile !== '' ? $classifierFile : null;
            $base['alt_source'] = $classifierDir !== '' ? 'classifier' : null;
            if (!empty($mapRow['show_id'])) {
                $base['show_id'] = (int) $mapRow['show_id'];
            }
            if (!empty($mapRow['media_type_id'])) {
                $base['media_type_id'] = (int) $mapRow['media_type_id'];
            }
            $parsed = ProposalFilenameParser::parseFilename($mapFile);
            if ($parsed['date'] !== null) {
                $base['file_date'] = $parsed['date'];
            }
            if ($parsed['time'] !== null) {
                $base['file_time'] = $parsed['time'];
            }
        } else {
            $base['proposed_dir'] = $classifierDir !== '' ? $classifierDir : $mapDir;
            $base['proposed_filename'] = $classifierFile !== '' ? $classifierFile : $mapFile;
            $base['proposed_source'] = $classifierDir !== '' ? 'classifier' : 'legacy_map';
        }

        $base['confidence'] = $this->effectiveConfidence(
            $agreement,
            $curatorScore,
            $classifierConf,
            $notes
        );
        if ($agreement === 'conflict' || $this->shouldFlag($notes, $curatorScore)) {
            $base['status'] = 'FLAGGED';
        }
        $base['reconcile_signals'] = $signals;

        return $base;
    }

    /** @param array<string, mixed> $mapRow */
    /** @return array{agreement: string, signals: list<string>} */
    private function compareProposals(
        string $classifierDir,
        string $classifierFile,
        string $mapDir,
        string $mapFile,
        array $mapRow
    ): array {
        $signals = [];
        if ($classifierDir === '' && $classifierFile === '') {
            $signals[] = 'map:only source with target';

            return ['agreement' => 'map_only', 'signals' => $signals];
        }

        $cFile = ProposalFilenameParser::parseFilename($classifierFile);
        $mFile = ProposalFilenameParser::parseFilename($mapFile);
        $cDir  = ProposalFilenameParser::parseDir($classifierDir);
        $mDir  = ProposalFilenameParser::parseDir($mapDir);

        $showMatch = ($cFile['show_abbr'] ?? null) === ($mFile['show_abbr'] ?? null)
            && ($cFile['show_abbr'] ?? null) !== null;
        if (!$showMatch && strtoupper((string) ($mapRow['show_abbr'] ?? '')) === ($cFile['show_abbr'] ?? '')) {
            $showMatch = ($mFile['show_abbr'] ?? null) === strtoupper((string) $mapRow['show_abbr']);
        }

        $cType = $this->resolveMediaTypeId($cFile['media_token'] ?? null, $cDir['folder_type'] ?? null);
        $mType = (int) ($mapRow['media_type_id'] ?? 0) ?: $this->resolveMediaTypeId(
            $mFile['media_token'] ?? null,
            $mDir['folder_type'] ?? null
        );
        $typeMatch = $cType !== null && $mType !== null && $cType === $mType;

        $dateMatch = ($cFile['date'] ?? null) !== null
            && ($cFile['date'] ?? null) === ($mFile['date'] ?? null);
        $timeMatch = ($cFile['time'] ?? null) !== null
            && ($cFile['time'] ?? null) === ($mFile['time'] ?? null);

        $pathMatch = ProposalFilenameParser::normalizePath($classifierDir) === ProposalFilenameParser::normalizePath($mapDir)
            && strcasecmp($classifierFile, $mapFile) === 0;

        if ($pathMatch) {
            $signals[] = 'map:full path agreement';

            return ['agreement' => 'match', 'signals' => $signals];
        }

        if ($showMatch && $typeMatch && $dateMatch && $timeMatch) {
            $signals[] = 'map:semantic agreement';

            return ['agreement' => 'match', 'signals' => $signals];
        }

        if ($showMatch && $typeMatch && $dateMatch) {
            $signals[] = 'map:partial agreement (time differs)';

            return ['agreement' => 'partial', 'signals' => $signals];
        }

        if (!$showMatch || !$typeMatch) {
            $signals[] = 'map:conflict with classifier';

            return ['agreement' => 'conflict', 'signals' => $signals];
        }

        $signals[] = 'map:partial agreement';

        return ['agreement' => 'partial', 'signals' => $signals];
    }

    private function recommendMapPrimary(string $agreement, int $curatorScore, string $classifierDir, string $mapDir): bool
    {
        if ($classifierDir === '') {
            return true;
        }
        if ($agreement === 'match' && $curatorScore >= 7) {
            return true;
        }
        if ($agreement === 'map_only') {
            return true;
        }
        if ($agreement === 'partial' && $curatorScore >= 8) {
            return true;
        }

        return false;
    }

    private function effectiveConfidence(
        string $agreement,
        int $curatorScore,
        string $classifierConf,
        string $notes
    ): string {
        if (stripos($notes, 'review manually') !== false || $curatorScore <= 5) {
            if ($agreement === 'conflict') {
                return 'LOW';
            }
            if ($agreement === 'match' && $curatorScore >= 9) {
                return 'MEDIUM';
            }

            return $curatorScore <= 3 ? 'LOW' : 'MEDIUM';
        }

        return match ($agreement) {
            'match' => $curatorScore >= 6 ? 'HIGH' : 'MEDIUM',
            'partial' => 'MEDIUM',
            'map_only' => $curatorScore >= 9 ? 'MEDIUM' : ($curatorScore >= 6 ? 'MEDIUM' : 'LOW'),
            'conflict' => 'LOW',
            default => $classifierConf,
        };
    }

    private function shouldFlag(string $notes, int $curatorScore): bool
    {
        return stripos($notes, 'review manually') !== false
            || $curatorScore <= 5;
    }

    private function resolveMediaTypeId(?string $token, ?string $folderType): ?int
    {
        if ($token !== null && $token !== '') {
            $type = $this->mediaTypes->resolve($token);

            return $type !== null ? (int) $type['id'] : null;
        }
        if ($folderType !== null && $folderType !== '') {
            $type = $this->mediaTypes->resolve($folderType);

            return $type !== null ? (int) $type['id'] : null;
        }

        return null;
    }
}
