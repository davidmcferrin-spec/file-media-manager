<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\ConversionRuleRepository;
use MediaManager\Repositories\MediaTypeRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SystemRepository;

final class Classifier
{
    /** @var list<array<string, mixed>> */
    private array $shows;

    /** @var list<array<string, mixed>> */
    private array $mediaTypes;

    /** @var list<array<string, mixed>> */
    private array $conversionRules;

    private int $splitFlagThreshold;
    private int $splitStrongThreshold;

    public function __construct(
        private readonly ShowRepository $showRepo = new ShowRepository(),
        private readonly MediaTypeRepository $mediaTypeRepo = new MediaTypeRepository(),
        private readonly ConversionRuleRepository $conversionRepo = new ConversionRuleRepository(),
        private readonly SystemRepository $systemRepo = new SystemRepository(),
        private readonly ScheduleLookupService $scheduleLookup = new ScheduleLookupService(),
        private readonly ScheduleSplitSuggester $scheduleSplit = new ScheduleSplitSuggester(),
    ) {
        $this->shows           = $showRepo->all(true);
        $this->mediaTypes      = $mediaTypeRepo->all(true);
        $this->conversionRules = $conversionRepo->all();
        $this->splitFlagThreshold = (int) ($systemRepo->get('split_flag_threshold_seconds') ?? env('SPLIT_FLAG_THRESHOLD_SECONDS', 4260));
        $this->splitStrongThreshold = (int) ($systemRepo->get('split_strong_threshold_seconds') ?? env('SPLIT_STRONG_THRESHOLD_SECONDS', 10800));
    }

    /**
     * @param list<string> $sidecarPaths Absolute paths of paired sidecars in same directory
     * @param array<string, mixed>|null $ffprobe FFprobe metadata array
     */
    public function classify(
        string $fullPath,
        string $mountPath,
        ?array $ffprobe = null,
        array $sidecarPaths = []
    ): ClassifierResult {
        $signals = [];
        $fullPath = str_replace('\\', '/', $fullPath);
        $mountPath = rtrim(str_replace('\\', '/', $mountPath), '/');

        $relative = ltrim(substr($fullPath, strlen($mountPath)), '/');
        $segments = $relative !== '' ? explode('/', $relative) : [];
        $filename = (string) array_pop($segments);
        $dir      = $mountPath . ($segments !== [] ? '/' . implode('/', $segments) : '');

        // ── Show ─────────────────────────────────────────────
        $showMatch = $this->matchShow($segments, $filename, $signals);

        // ── Media type ───────────────────────────────────────
        $typeMatch = $this->matchMediaType($segments, $filename, $signals);

        // ── Date / time (FFprobe-preferred, LOW default trust) ─
        $datetime = FileDateTimeResolver::resolve($filename, $ffprobe);
        $date     = $datetime['date'];
        $time     = $datetime['time'];
        foreach ($datetime['signals'] as $dtSignal) {
            $signals[] = $dtSignal;
        }

        if ($date === null) {
            $date = DateNormalizer::mergePathDate(null, array_merge($segments, [$filename]));
            if ($date !== null) {
                $signals[] = 'path:YYYY/MM (day defaulted to 01)';
            }
        }

        // ── Schedule show lookup when path/filename did not match ─
        if (($showMatch['id'] ?? null) === null && $date !== null && $time !== null) {
            $scheduleMatch = $this->scheduleLookup->match($date, $time);
            if ($scheduleMatch !== null) {
                $showMatch = [
                    'id'           => $scheduleMatch['show_id'],
                    'abbreviation' => $scheduleMatch['show_abbr'],
                ];
                $signals[] = $scheduleMatch['signal'] . ' (' . $scheduleMatch['confidence'] . ')';
            }
        }

        // ── GISO guest name ──────────────────────────────────
        $guestName = $this->extractGuestName($filename, $typeMatch['abbreviation'] ?? '');

        // ── Build proposed names ─────────────────────────────
        $showAbbr = $showMatch['abbreviation'] ?? null;
        $typeAbbr = $typeMatch['abbreviation'] ?? null;
        $typeName = $typeMatch['name'] ?? null;

        $proposedDir      = null;
        $proposedFilename = null;

        if ($showAbbr !== null && $date !== null && $typeAbbr !== null) {
            $year  = substr($date, 0, 4);
            $month = substr($date, 4, 2);
            $folderType = $this->folderNameForType(
                $typeMatch['id'] ?? null,
                $typeMatch['name'] ?? '',
                $typeMatch['abbreviation'] ?? ''
            );
            $proposedDir = $showAbbr . '/' . $year . '/' . $month . '/' . $folderType;

            $ext = MediaExtensions::extension($filename);
            if ($guestName !== null && strtoupper($typeAbbr) === 'GISO') {
                $proposedFilename = sprintf(
                    '%s_%s_%s_GISO_%s.%s',
                    $showAbbr,
                    $date,
                    $time ?? '0000',
                    $guestName,
                    $ext
                );
            } else {
                $proposedFilename = sprintf(
                    '%s_%s_%s_%s.%s',
                    $showAbbr,
                    $date,
                    $time ?? '0000',
                    $typeAbbr,
                    $ext
                );
            }
        }

        // ── Sidecar proposed names ───────────────────────────
        $sidecarEntries = [];
        if ($proposedFilename !== null) {
            $stem = pathinfo($proposedFilename, PATHINFO_FILENAME);
            foreach ($sidecarPaths as $sidecarPath) {
                $scExt = MediaExtensions::extension($sidecarPath);
                $sidecarEntries[] = [
                    'original_path'      => $sidecarPath,
                    'proposed_filename'  => $stem . '.' . $scExt,
                ];
            }
        }

        // ── Confidence ───────────────────────────────────────
        $confidence = $this->scoreConfidence($showMatch, $typeMatch, $date, $time, $signals);

        // ── Policy exact match ───────────────────────────────
        $policyExact = false;
        if ($proposedDir !== null && $proposedFilename !== null) {
            $policyExact = $this->isPolicyExactMatch(
                $relative,
                $filename,
                $proposedDir,
                $proposedFilename
            );
            if ($policyExact) {
                $signals[] = 'policy:already compliant';
            }
        }

        // ── Split flagging ───────────────────────────────────
        $duration = isset($ffprobe['duration']) ? (float) $ffprobe['duration'] : null;
        $needsSplit = $duration !== null && $duration >= $this->splitFlagThreshold;
        $splitNotes = '';
        if ($needsSplit) {
            $splitNotes = $duration >= $this->splitStrongThreshold
                ? 'Duration ≥ 3h — strong split candidate'
                : 'Duration > 1h 11m — review for split';
            $signals[] = 'split:duration threshold';
        }

        $scheduleSplit = $this->scheduleSplit->suggest($date, $time, $duration);
        if ($scheduleSplit['needs_split']) {
            $needsSplit = true;
            $splitNotes = $scheduleSplit['notes'] !== ''
                ? ($splitNotes !== '' ? $splitNotes . "\n\n" : '') . $scheduleSplit['notes']
                : $splitNotes;
            foreach ($scheduleSplit['signals'] as $splitSignal) {
                $signals[] = $splitSignal;
            }
        }

        return new ClassifierResult(
            showId: $showMatch['id'] ?? null,
            showAbbreviation: $showAbbr,
            mediaTypeId: $typeMatch['id'] ?? null,
            mediaTypeName: $typeName,
            mediaTypeAbbreviation: $typeAbbr,
            fileDate: $date,
            fileTime: $time,
            proposedDir: $proposedDir,
            proposedFilename: $proposedFilename,
            confidence: $confidence,
            signals: $signals,
            needsSplit: $needsSplit,
            splitNotes: $splitNotes,
            policyExactMatch: $policyExact,
            sidecars: $sidecarEntries,
            guestName: $guestName,
        );
    }

    /** @param list<string> $segments */
    /** @param list<string> $signals */
    /** @return array{id: ?int, abbreviation: ?string} */
    private function matchShow(array $segments, string $filename, array &$signals): array
    {
        $haystack = strtolower(implode(' ', $segments) . ' ' . $filename);

        foreach ($this->shows as $show) {
            $abbr = (string) $show['abbreviation'];
            if ($this->tokenMatch($haystack, strtolower($abbr))) {
                $signals[] = 'show:abbreviation ' . $abbr;
                return ['id' => (int) $show['id'], 'abbreviation' => $abbr];
            }

            $aliases = json_decode((string) ($show['aliases'] ?? '[]'), true);
            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    if (is_string($alias) && $this->tokenMatch($haystack, strtolower($alias))) {
                        $signals[] = 'show:alias ' . $alias;
                        return ['id' => (int) $show['id'], 'abbreviation' => $abbr];
                    }
                }
            }
        }

        foreach ($this->conversionRules as $rule) {
            if (($rule['category'] ?? '') !== 'show' || empty($rule['active'])) {
                continue;
            }
            $alias = (string) $rule['alias'];
            if ($this->tokenMatch($haystack, $alias)) {
                $signals[] = 'show:conversion ' . $alias;
                return [
                    'id'           => (int) $rule['show_id'],
                    'abbreviation' => (string) ($rule['show_abbreviation'] ?? ''),
                ];
            }
        }

        // First path segment often is show folder
        if (isset($segments[0])) {
            $folder = strtolower($segments[0]);
            foreach ($this->shows as $show) {
                if (strtolower((string) $show['abbreviation']) === $folder
                    || strtolower((string) $show['canonical_name']) === $folder) {
                    $signals[] = 'show:path folder ' . $segments[0];
                    return ['id' => (int) $show['id'], 'abbreviation' => (string) $show['abbreviation']];
                }
            }
        }

        return ['id' => null, 'abbreviation' => null];
    }

    /** @param list<string> $segments */
    /** @param list<string> $signals */
    /** @return array{id: ?int, name: ?string, abbreviation: ?string} */
    private function matchMediaType(array $segments, string $filename, array &$signals): array
    {
        $folderType = $segments !== [] ? strtoupper($segments[count($segments) - 1]) : '';
        $typeFromFolder = $this->resolveMediaTypeByToken($folderType);
        if ($typeFromFolder !== null) {
            $signals[] = 'media_type:path folder ' . $folderType;
            return $typeFromFolder;
        }

        $haystack = strtolower($filename);

        // Longest conversion alias first
        $rules = $this->conversionRules;
        usort($rules, fn ($a, $b) => strlen((string) $b['alias']) <=> strlen((string) $a['alias']));
        foreach ($rules as $rule) {
            if (($rule['category'] ?? '') !== 'media_type' || empty($rule['active'])) {
                continue;
            }
            $alias = (string) $rule['alias'];
            if (str_contains($haystack, $alias)) {
                $signals[] = 'media_type:conversion ' . $alias;
                return [
                    'id'           => (int) $rule['media_type_id'],
                    'name'         => (string) ($rule['media_type_name'] ?? ''),
                    'abbreviation' => (string) ($rule['media_type_abbreviation'] ?? ''),
                ];
            }
        }

        $tokens = ['GISO', 'ISO', 'CLEAN', 'PROGRAM', 'PGM', 'PRETAPE', 'PRE-TAPE', 'RAW', 'LIVE CLEAN'];
        foreach ($tokens as $token) {
            if (stripos($filename, $token) !== false) {
                $normalized = match (strtoupper(str_replace('-', ' ', $token))) {
                    'PGM'         => 'PROGRAM',
                    'PRETAPE', 'PRE TAPE' => 'CLEAN',
                    'LIVE CLEAN'  => 'CLEAN',
                    default       => strtoupper(str_replace(' ', '', $token)),
                };
                $resolved = $this->resolveMediaTypeByToken($normalized);
                if ($resolved !== null) {
                    $signals[] = 'media_type:filename token ' . $token;
                    return $resolved;
                }
            }
        }

        return ['id' => null, 'name' => null, 'abbreviation' => null];
    }

    /** @return array{id: int, name: string, abbreviation: string}|null */
    private function resolveMediaTypeByToken(string $token): ?array
    {
        $token = strtoupper(trim($token));
        if ($token === '') {
            return null;
        }

        foreach ($this->mediaTypes as $mt) {
            $name = strtoupper((string) $mt['name']);
            $abbr = strtoupper((string) $mt['abbreviation']);
            if ($token === $name || $token === $abbr) {
                return [
                    'id'           => (int) $mt['id'],
                    'name'         => (string) $mt['name'],
                    'abbreviation' => (string) $mt['abbreviation'],
                ];
            }
        }

        // ISO folder holds both ISO and GISO
        if ($token === 'ISO' || $token === 'GISO') {
            foreach ($this->mediaTypes as $mt) {
                if (strtoupper((string) $mt['abbreviation']) === $token) {
                    return [
                        'id'           => (int) $mt['id'],
                        'name'         => (string) $mt['name'],
                        'abbreviation' => (string) $mt['abbreviation'],
                    ];
                }
            }
        }

        return null;
    }

    private function folderNameForType(?int $typeId, string $typeName, string $typeAbbr): string
    {
        if ($typeId !== null) {
            foreach ($this->mediaTypes as $mt) {
                if ((int) $mt['id'] === $typeId) {
                    return (string) ($mt['folder_name'] ?? $mt['name'] ?? $typeAbbr);
                }
            }
        }

        return $this->folderMediaType($typeName, $typeAbbr);
    }

    private function folderMediaType(string $typeName, string $typeAbbr): string
    {
        $upper = strtoupper($typeAbbr);
        if ($upper === 'ISO' || $upper === 'GISO') {
            return 'ISO';
        }

        return $typeAbbr;
    }

    private function extractGuestName(string $filename, string $typeAbbr): ?string
    {
        if (strtoupper($typeAbbr) !== 'GISO' && stripos($filename, 'GISO') === false) {
            return null;
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/GISO[_\s-]+(.+)$/i', $base, $m) === 1) {
            return $this->sanitizeGuestName($m[1]);
        }

        return null;
    }

    private function sanitizeGuestName(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('/[^\w\s-]/', '', $raw) ?? $raw;
        $raw = preg_replace('/\s+/', '_', $raw) ?? $raw;

        return $raw;
    }

    /** @param list<string> $signals */
    private function scoreConfidence(
        array $showMatch,
        array $typeMatch,
        ?string $date,
        ?string $time,
        array $signals
    ): string {
        $score = 0;
        if ($showMatch['id'] !== null) {
            $score += 2;
        }
        if ($typeMatch['id'] !== null) {
            $score += 2;
        }
        if ($date !== null) {
            $score += 2;
        }
        if ($time !== null) {
            $score += 1;
        }

        if ($score >= 6) {
            return 'HIGH';
        }
        if ($score >= 4) {
            return 'MEDIUM';
        }

        foreach ($signals as $signal) {
            if (str_starts_with($signal, 'schedule:')) {
                return 'MEDIUM';
            }
        }

        return 'LOW';
    }

    private function isPolicyExactMatch(
        string $relativePath,
        string $filename,
        string $proposedDir,
        string $proposedFilename
    ): bool {
        $parts     = explode('/', $relativePath);
        $actualDir = implode('/', array_slice($parts, 0, -1));
        $norm      = static fn (string $s): string => strtolower(preg_replace('/[\s_]+/', '', $s) ?? $s);

        return $norm($actualDir) === $norm($proposedDir)
            && strcasecmp($filename, $proposedFilename) === 0;
    }

    private function tokenMatch(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return preg_match('/(?:^|[\s_\/-])' . preg_quote($needle, '/') . '(?:[\s_\/-]|$)/i', $haystack) === 1
            || str_contains($haystack, $needle);
    }
}
