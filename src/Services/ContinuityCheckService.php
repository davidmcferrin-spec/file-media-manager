<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\ContinuityCheckLogRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\MediaTypeRepository;
use MediaManager\Repositories\ProgramScheduleRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SystemRepository;

/**
 * Second-pass broadcast continuity check layered on pattern classification.
 * Refines confidence (and occasionally show) using the local continuity engine.
 */
final class ContinuityCheckService
{
    private ?bool $reachable = null;

    private float $reachableCheckedAt = 0.0;

    /** @var list<array<string, mixed>>|null */
    private ?array $showCatalog = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $exemplars = null;

    private readonly ContinuityCheckClient $client;

    public function __construct(
        ?ContinuityCheckClient $client = null,
        private readonly ShowRepository $shows = new ShowRepository(),
        private readonly MediaTypeRepository $mediaTypes = new MediaTypeRepository(),
        private readonly ProgramScheduleRepository $schedule = new ProgramScheduleRepository(),
        private readonly FileRepository $files = new FileRepository(),
        private readonly SystemRepository $system = new SystemRepository(),
        private readonly ContinuityCheckLogRepository $log = new ContinuityCheckLogRepository(),
    ) {
        $this->client = $client ?? ContinuityCheckClient::fromEnv();
    }

    public static function create(): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        $setting = $this->system->get('continuity_check_enabled');
        if ($setting !== null && $setting !== '') {
            return in_array(strtolower(trim($setting)), ['1', 'true', 'yes', 'on'], true);
        }

        return env('CONTINUITY_CHECK_ENABLED', false) === true;
    }

    public function refine(
        ClassifierResult $result,
        string $originalPath,
        string $originalFilename
    ): ClassifierResult {
        if (!$this->isEnabled()) {
            return $result;
        }
        if ($result->policyExactMatch) {
            return $result;
        }
        if ($result->showId === null && $result->confidence === 'LOW') {
            return $result;
        }
        if (!$this->engineAvailable()) {
            return $result;
        }

        $started = microtime(true);
        $asked = $this->askEngine($result, $originalPath, $originalFilename);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $verdict = $asked['verdict'];

        if ($verdict === null) {
            $detail = trim((string) ($asked['transport_error'] ?? ''));
            if ($detail === '') {
                $detail = 'No usable response from continuity engine';
            }
            $this->safeLog([
                'original_path'           => $originalPath,
                'original_filename'       => $originalFilename,
                'rule_show_id'            => $result->showId,
                'rule_show_abbr'          => $result->showAbbreviation,
                'rule_confidence'         => $result->confidence,
                'rule_proposed_filename'  => $result->proposedFilename,
                'rule_signals'            => $result->signals,
                'final_confidence'        => $result->confidence,
                'final_show_id'           => $result->showId,
                'final_show_abbr'         => $result->showAbbreviation,
                'final_proposed_filename' => $result->proposedFilename,
                'signal'                  => 'continuity:error',
                'outcome'                 => 'error',
                'duration_ms'             => $durationMs,
                'engine_reason'           => $detail,
                'seed_packet'             => $asked['seed_packet'],
                'engine_raw'              => $asked['raw_content'],
                'http_status'             => $asked['http_status'],
                'transport_error'         => $asked['transport_error'],
            ]);

            return $result;
        }

        $adjusted = $this->applyVerdict($result, $verdict, $originalFilename);
        $merged = self::mergeVerdict($result->confidence, $verdict);
        $agree = filter_var($verdict['agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $outcome = match ($merged['signal']) {
            'continuity:confirmed' => 'confirmed',
            'continuity:conflict'  => 'conflict',
            default                => 'review',
        };

        $this->safeLog([
            'original_path'           => $originalPath,
            'original_filename'       => $originalFilename,
            'rule_show_id'            => $result->showId,
            'rule_show_abbr'          => $result->showAbbreviation,
            'rule_confidence'         => $result->confidence,
            'rule_proposed_filename'  => $result->proposedFilename,
            'rule_signals'            => $result->signals,
            'engine_agree'            => $agree,
            'engine_confidence'       => isset($verdict['confidence']) ? strtoupper((string) $verdict['confidence']) : null,
            'engine_show_id'          => isset($verdict['show_id']) && is_numeric($verdict['show_id'])
                ? (int) $verdict['show_id'] : null,
            'engine_reason'           => trim((string) ($verdict['reason'] ?? '')),
            'final_confidence'        => $adjusted->confidence,
            'final_show_id'           => $adjusted->showId,
            'final_show_abbr'         => $adjusted->showAbbreviation,
            'final_proposed_filename' => $adjusted->proposedFilename,
            'signal'                  => $merged['signal'],
            'outcome'                 => $outcome,
            'duration_ms'             => $durationMs,
            'seed_packet'             => $asked['seed_packet'],
            'engine_raw'              => $asked['raw_content'],
            'http_status'             => $asked['http_status'],
            'transport_error'         => $asked['transport_error'],
        ]);

        return $adjusted;
    }

    /**
     * Status snapshot for the private continuity lab page.
     *
     * @return array{
     *   enabled: bool,
     *   reachable: bool,
     *   latency_ms: ?int,
     *   base_url: string,
     *   pack: string,
     *   timeout_seconds: int,
     *   packs: list<string>
     * }
     */
    public function status(): array
    {
        $probe = $this->client->probe();

        return [
            'enabled'          => $this->isEnabled(),
            'reachable'        => $probe['reachable'],
            'latency_ms'       => $probe['latency_ms'],
            'base_url'         => $this->client->baseUrl(),
            'pack'             => $this->client->model(),
            'timeout_seconds'  => $this->client->timeoutSeconds(),
            'packs'            => $probe['packs'],
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   duration_ms: int,
     *   verdict: ?array<string, mixed>,
     *   raw_content: string,
     *   http_status: ?int,
     *   transport_error: string,
     *   pack_loaded: bool,
     *   packs: list<string>
     * }
     */
    public function selfTest(): array
    {
        return $this->client->selfTest();
    }

    /** @param array<string, mixed> $row */
    private function safeLog(array $row): void
    {
        try {
            $this->log->insert($row);
        } catch (\Throwable $e) {
            error_log('[continuity] log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Pure merge rules for tests / inspection.
     *
     * @param array{agree?: mixed, confidence?: mixed, show_id?: mixed, reason?: mixed} $verdict
     * @return array{confidence: string, adopt_show_id: ?int, signal: string}
     */
    public static function mergeVerdict(string $ruleConfidence, array $verdict): array
    {
        $agree = filter_var($verdict['agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $engineConf = strtoupper(trim((string) ($verdict['confidence'] ?? '')));
        if (!in_array($engineConf, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            $engineConf = 'MEDIUM';
        }
        $adoptShowId = isset($verdict['show_id']) && is_numeric($verdict['show_id'])
            ? (int) $verdict['show_id']
            : null;
        if ($adoptShowId !== null && $adoptShowId <= 0) {
            $adoptShowId = null;
        }

        $rank = static fn (string $c): int => match ($c) {
            'HIGH' => 3,
            'MEDIUM' => 2,
            default => 1,
        };
        $fromRank = static fn (int $r): string => match (true) {
            $r >= 3 => 'HIGH',
            $r === 2 => 'MEDIUM',
            default => 'LOW',
        };

        if ($agree === true) {
            // Never raise above the pattern score; confirm only.
            return [
                'confidence'    => $fromRank(min($rank($ruleConfidence), $rank($engineConf))),
                'adopt_show_id' => null,
                'signal'        => 'continuity:confirmed',
            ];
        }

        if ($agree === false) {
            // Disputed mappings drop hard — especially false HIGHs.
            $final = $ruleConfidence === 'HIGH' ? 'LOW' : 'LOW';

            return [
                'confidence'    => $final,
                'adopt_show_id' => $adoptShowId,
                'signal'        => 'continuity:conflict',
            ];
        }

        // Uncertain / missing agree — blunt HIGH only
        return [
            'confidence'    => $ruleConfidence === 'HIGH' ? 'MEDIUM' : $ruleConfidence,
            'adopt_show_id' => null,
            'signal'        => 'continuity:review',
        ];
    }

    private function engineAvailable(): bool
    {
        $now = microtime(true);
        if ($this->reachable !== null && ($now - $this->reachableCheckedAt) < 60.0) {
            return $this->reachable;
        }
        $this->reachableCheckedAt = $now;
        $this->reachable = $this->client->isReachable();

        return $this->reachable;
    }

    /**
     * @return array{
     *   verdict: ?array<string, mixed>,
     *   seed_packet: array<string, mixed>,
     *   raw_content: string,
     *   http_status: ?int,
     *   transport_error: string
     * }
     */
    private function askEngine(
        ClassifierResult $result,
        string $originalPath,
        string $originalFilename
    ): array {
        $shows = $this->catalog();
        $timeline = $this->timelineFor($result->fileDate, $result->fileTime);
        $examples = $this->approvedExemplars();

        $system = <<<'PROMPT'
You are a broadcast archive continuity checker for NewsNation media files.
Decide whether the proposed show mapping is consistent with the dictionary, air schedule, and approved examples.
Rules:
- Choose show_id ONLY from the provided shows list, or null.
- Prefer schedule alignment when date/time are present.
- Short or ambiguous path tokens are weak evidence — do not over-trust them.
- Never invent show abbreviations or filenames.
- Respond with JSON only, no prose:
{"agree":true|false,"confidence":"HIGH"|"MEDIUM"|"LOW","show_id":null|number,"reason":"short"}
PROMPT;

        $seedPacket = [
            'original_path'     => $originalPath,
            'original_filename' => $originalFilename,
            'proposal'          => [
                'show_id'           => $result->showId,
                'show_abbreviation' => $result->showAbbreviation,
                'media_type'        => $result->mediaTypeAbbreviation,
                'file_date'         => $result->fileDate,
                'file_time'         => $result->fileTime,
                'proposed_dir'      => $result->proposedDir,
                'proposed_filename' => $result->proposedFilename,
                'confidence'        => $result->confidence,
                'signals'           => $result->signals,
            ],
            'shows'     => $shows,
            'timeline'  => $timeline,
            'examples'  => $examples,
            'system'    => $system,
        ];

        // Leaner payload for the engine (full seed_packet still logged for Lab).
        $userPayload = [
            'original_path'     => $originalPath,
            'original_filename' => $originalFilename,
            'proposal'          => $seedPacket['proposal'],
            'shows'             => array_map(static function (array $s): array {
                return [
                    'id'             => $s['id'],
                    'abbreviation'   => $s['abbreviation'],
                    'canonical_name' => $s['canonical_name'],
                    'aliases'        => array_slice($s['aliases'] ?? [], 0, 8),
                ];
            }, $shows),
            'timeline' => $timeline,
            'examples' => array_map(static function (array $ex): array {
                return [
                    'original_filename'  => $ex['original_filename'] ?? '',
                    'proposed_filename'  => $ex['proposed_filename'] ?? '',
                    'show_abbr'          => $ex['show_abbr'] ?? '',
                    'file_date'          => $ex['file_date'] ?? null,
                    'file_time'          => $ex['file_time'] ?? null,
                ];
            }, $examples),
        ];

        $user = json_encode($userPayload, JSON_THROW_ON_ERROR);
        $response = $this->client->completeJson($system, $user);
        // Only mark engine offline on transport failures (timeout / HTTP), not parse issues.
        if ($response['verdict'] === null && !empty($response['transport_failed'])) {
            $this->reachable = false;
            $this->reachableCheckedAt = microtime(true);
        }

        return [
            'verdict'         => $response['verdict'],
            'seed_packet'     => $seedPacket,
            'raw_content'     => $response['raw_content'],
            'http_status'     => $response['http_status'],
            'transport_error' => $response['transport_error'],
        ];
    }

    /**
     * @param array<string, mixed> $verdict
     */
    private function applyVerdict(
        ClassifierResult $result,
        array $verdict,
        string $originalFilename
    ): ClassifierResult {
        $merged = self::mergeVerdict($result->confidence, $verdict);
        $signals = $result->signals;
        $signals[] = $merged['signal'];
        $reason = trim((string) ($verdict['reason'] ?? ''));
        if ($reason !== '') {
            $signals[] = 'continuity:note ' . mb_substr($reason, 0, 160);
        }

        $showId = $result->showId;
        $showAbbr = $result->showAbbreviation;
        $proposedDir = $result->proposedDir;
        $proposedFilename = $result->proposedFilename;

        $adoptId = $merged['adopt_show_id'];
        if ($adoptId !== null && $adoptId !== $result->showId) {
            $show = $this->shows->findById($adoptId);
            if ($show !== null) {
                $showId = $adoptId;
                $showAbbr = (string) $show['abbreviation'];
                $signals[] = 'continuity:show adjusted';

                if ($result->fileDate !== null && $result->mediaTypeAbbreviation !== null) {
                    $type = $result->mediaTypeId !== null
                        ? $this->mediaTypes->findById($result->mediaTypeId)
                        : null;
                    $folder = $type !== null
                        ? (string) ($type['folder_name'] ?? $type['name'])
                        : (string) $result->mediaTypeAbbreviation;
                    $guest = ProposalPathBuilder::guestFromProposed($result->proposedFilename);
                    $built = ProposalPathBuilder::build(
                        $showAbbr,
                        $result->fileDate,
                        $result->fileTime,
                        (string) ($type['abbreviation'] ?? $result->mediaTypeAbbreviation),
                        $folder,
                        $originalFilename,
                        $guest
                    );
                    if ($built !== null) {
                        $proposedDir = $built['proposed_dir'];
                        $proposedFilename = $built['proposed_filename'];
                    }
                }
            }
        }

        return $result->withAdjustments(
            $merged['confidence'],
            $signals,
            $showId,
            $showAbbr,
            $proposedDir,
            $proposedFilename
        );
    }

    /** @return list<array<string, mixed>> */
    private function catalog(): array
    {
        if ($this->showCatalog !== null) {
            return $this->showCatalog;
        }
        $rows = $this->shows->all(true);
        $out = [];
        foreach ($rows as $row) {
            $aliases = json_decode((string) ($row['aliases'] ?? '[]'), true);
            $out[] = [
                'id'             => (int) $row['id'],
                'abbreviation'   => (string) $row['abbreviation'],
                'canonical_name' => (string) $row['canonical_name'],
                'aliases'        => is_array($aliases) ? array_values(array_filter($aliases, 'is_string')) : [],
            ];
        }
        $this->showCatalog = $out;

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function timelineFor(?string $dateYmd, ?string $timeHhmm): array
    {
        if ($dateYmd === null || !DateNormalizer::isValidDate($dateYmd)) {
            return [];
        }
        $minutes = DateNormalizer::timeToMinutes($timeHhmm) ?? (12 * 60);
        $dayBit = ScheduleTimeParser::dayBitFromDate($dateYmd);
        if ($dayBit === 0) {
            return [];
        }
        $rows = $this->schedule->matchAt($dateYmd, $minutes, $dayBit);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'show_id'   => (int) $row['show_id'],
                'show_abbr' => (string) ($row['show_abbr'] ?? ''),
                'title'     => (string) ($row['title'] ?? ''),
                'hour'      => substr((string) ($row['hour_start_et'] ?? ''), 0, 5),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function approvedExemplars(): array
    {
        if ($this->exemplars !== null) {
            return $this->exemplars;
        }
        $this->exemplars = $this->files->continuityExemplars(12);

        return $this->exemplars;
    }
}
