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
 * Refines confidence, show, date/time, and media type using the local continuity engine.
 */
final class ContinuityCheckService
{
    private ?bool $reachable = null;

    private float $reachableCheckedAt = 0.0;

    /** @var list<array<string, mixed>>|null */
    private ?array $showCatalog = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $mediaTypeCatalog = null;

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
        string $originalFilename,
        ?int $fileId = null
    ): ClassifierResult {
        if (!$this->isEnabled()) {
            return $result;
        }
        if ($result->policyExactMatch) {
            return $result;
        }
        if ($result->showId === null
            && in_array($result->confidence, ['LOW', 'UNEVALUATED'], true)
        ) {
            return $result;
        }
        if (!$this->engineAvailable()) {
            return $result;
        }

        $started = microtime(true);
        $asked = $this->askEngine($result, $originalPath, $originalFilename);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $verdict = $asked['verdict'];

        $baseLog = [
            'file_id'                 => $fileId,
            'original_path'           => $originalPath,
            'original_filename'       => $originalFilename,
            'rule_show_id'            => $result->showId,
            'rule_show_abbr'          => $result->showAbbreviation,
            'rule_confidence'         => $result->confidence,
            'rule_proposed_filename'  => $result->proposedFilename,
            'rule_file_date'          => $result->fileDate,
            'rule_file_time'          => $result->fileTime,
            'rule_media_type_id'      => $result->mediaTypeId,
            'rule_media_type_abbr'    => $result->mediaTypeAbbreviation,
            'rule_signals'            => $result->signals,
            'seed_packet'             => $asked['seed_packet'],
            'engine_raw'              => $asked['raw_content'],
            'http_status'             => $asked['http_status'],
            'transport_error'         => $asked['transport_error'],
            'duration_ms'             => $durationMs,
        ];

        if ($verdict === null) {
            $detail = trim((string) ($asked['transport_error'] ?? ''));
            if ($detail === '') {
                $detail = 'No usable response from continuity engine';
            }
            $this->safeLog($baseLog + [
                'final_confidence'        => $result->confidence,
                'final_show_id'           => $result->showId,
                'final_show_abbr'         => $result->showAbbreviation,
                'final_proposed_filename' => $result->proposedFilename,
                'final_file_date'         => $result->fileDate,
                'final_file_time'         => $result->fileTime,
                'final_media_type_id'     => $result->mediaTypeId,
                'final_media_type_abbr'   => $result->mediaTypeAbbreviation,
                'signal'                  => 'continuity:error',
                'outcome'                 => 'error',
                'engine_reason'           => $detail,
            ]);

            return $result;
        }

        $adjusted = $this->applyVerdict($result, $verdict, $originalFilename);
        $merged = self::mergeVerdict($result->confidence, $verdict);
        $dt = self::mergeDateTime($result->fileDate, $result->fileTime, $verdict);
        $mt = $this->mergeMediaTypeFor($result, $verdict);
        $agree = filter_var($verdict['agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $outcome = match ($merged['signal']) {
            'continuity:confirmed' => 'confirmed',
            'continuity:conflict'  => 'conflict',
            default                => 'review',
        };

        $this->safeLog($baseLog + [
            'engine_agree'            => $agree,
            'engine_confidence'       => isset($verdict['confidence']) ? strtoupper((string) $verdict['confidence']) : null,
            'engine_show_id'          => isset($verdict['show_id']) && is_numeric($verdict['show_id'])
                ? (int) $verdict['show_id'] : null,
            'engine_file_date'        => $dt['engine_date'],
            'engine_file_time'        => $dt['engine_time'],
            'engine_media_type_id'    => $mt['engine_id'],
            'engine_media_type_abbr'  => $mt['engine_abbr'],
            'engine_reason'           => trim((string) ($verdict['reason'] ?? '')),
            'final_confidence'        => $adjusted->confidence,
            'final_show_id'           => $adjusted->showId,
            'final_show_abbr'         => $adjusted->showAbbreviation,
            'final_proposed_filename' => $adjusted->proposedFilename,
            'final_file_date'         => $adjusted->fileDate,
            'final_file_time'         => $adjusted->fileTime,
            'final_media_type_id'     => $adjusted->mediaTypeId,
            'final_media_type_abbr'   => $adjusted->mediaTypeAbbreviation,
            'signal'                  => $merged['signal'],
            'outcome'                 => $outcome,
        ]);

        return $adjusted;
    }

    /** Attach Catalog file id to the latest continuity row for a path (after first insert). */
    public function attachFileId(string $originalPath, int $fileId): void
    {
        try {
            $this->log->attachFileIdByPath($originalPath, $fileId);
        } catch (\Throwable $e) {
            error_log('[continuity] attach file_id failed: ' . $e->getMessage());
        }
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
     *   keep_alive: string,
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
            'keep_alive'       => $this->client->keepAlive(),
            'packs'            => $probe['packs'],
        ];
    }

    /**
     * Front-load pack into Ollama memory before a scan/reclassify loop.
     *
     * @return array{ok: bool, duration_ms: int, transport_error: string}|null
     *         null when continuity is disabled (nothing to warm)
     */
    public function warmEngine(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if (!$this->engineAvailable()) {
            return [
                'ok'              => false,
                'duration_ms'     => 0,
                'transport_error' => 'engine unreachable',
            ];
        }

        $result = $this->client->warmPack();
        if (!$result['ok']) {
            $this->reachable = false;
            $this->reachableCheckedAt = microtime(true);
        }

        return $result;
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
     * Merge engine date/time with rule values. Fills gaps; keeps rule when both disagree.
     *
     * @param array<string, mixed> $verdict
     * @return array{
     *   file_date: ?string,
     *   file_time: ?string,
     *   engine_date: ?string,
     *   engine_time: ?string,
     *   signals: list<string>,
     *   changed: bool
     * }
     */
    public static function mergeDateTime(?string $ruleDate, ?string $ruleTime, array $verdict): array
    {
        $engineDate = null;
        if (isset($verdict['file_date']) && $verdict['file_date'] !== null && $verdict['file_date'] !== '') {
            $engineDate = ProposalPathBuilder::normalizeDateInput((string) $verdict['file_date']);
        }
        $engineTime = null;
        if (isset($verdict['file_time']) && $verdict['file_time'] !== null && $verdict['file_time'] !== '') {
            $engineTime = DateNormalizer::normalizeTime((string) $verdict['file_time']);
        }

        $ruleDate = $ruleDate !== null && $ruleDate !== '' ? trim($ruleDate) : null;
        $ruleTime = $ruleTime !== null && $ruleTime !== '' ? trim($ruleTime) : null;
        if ($ruleDate !== null && !DateNormalizer::isValidDate($ruleDate)) {
            $ruleDate = null;
        }
        if ($ruleTime !== null) {
            $ruleTime = DateNormalizer::normalizeTime($ruleTime);
        }

        $finalDate = $ruleDate;
        $finalTime = $ruleTime;
        $signals = [];
        $changed = false;

        if ($finalDate === null && $engineDate !== null) {
            $finalDate = $engineDate;
            $signals[] = 'continuity:date filled';
            $changed = true;
        } elseif (
            $finalDate !== null
            && $engineDate !== null
            && $finalDate !== $engineDate
        ) {
            $signals[] = 'continuity:date conflict';
        }

        if ($finalTime === null && $engineTime !== null) {
            $finalTime = $engineTime;
            $signals[] = 'continuity:time filled';
            $changed = true;
        } elseif (
            $finalTime !== null
            && $engineTime !== null
            && $finalTime !== $engineTime
        ) {
            $signals[] = 'continuity:time conflict';
        }

        $dtAgree = filter_var($verdict['datetime_agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($dtAgree === false) {
            $signals[] = 'continuity:datetime disputed';
        } elseif ($dtAgree === true) {
            $signals[] = 'continuity:datetime confirmed';
        }

        return [
            'file_date'   => $finalDate,
            'file_time'   => $finalTime,
            'engine_date' => $engineDate,
            'engine_time' => $engineTime,
            'signals'     => $signals,
            'changed'     => $changed,
        ];
    }

    /**
     * Merge engine media type with rule values. Fills gaps; keeps rule when both disagree.
     *
     * @param array<string, mixed> $verdict
     * @param array<int, array{id: int, abbreviation: string, name: string, folder_name: string}> $typesById
     * @param array<string, int> $idsByAbbr uppercase abbreviation => id
     * @return array{
     *   media_type_id: ?int,
     *   media_type_name: ?string,
     *   media_type_abbreviation: ?string,
     *   folder_name: ?string,
     *   engine_id: ?int,
     *   engine_abbr: ?string,
     *   signals: list<string>,
     *   changed: bool
     * }
     */
    public static function mergeMediaType(
        ?int $ruleId,
        ?string $ruleAbbr,
        array $verdict,
        array $typesById,
        array $idsByAbbr
    ): array {
        $engineId = null;
        if (isset($verdict['media_type_id']) && is_numeric($verdict['media_type_id'])) {
            $candidate = (int) $verdict['media_type_id'];
            if ($candidate > 0 && isset($typesById[$candidate])) {
                $engineId = $candidate;
            }
        }
        if ($engineId === null) {
            $rawAbbr = $verdict['media_type'] ?? $verdict['media_type_abbr'] ?? null;
            if ($rawAbbr !== null && $rawAbbr !== '') {
                $key = strtoupper(trim((string) $rawAbbr));
                if ($key !== '' && isset($idsByAbbr[$key])) {
                    $engineId = $idsByAbbr[$key];
                }
            }
        }

        $engineAbbr = $engineId !== null ? (string) $typesById[$engineId]['abbreviation'] : null;

        if ($ruleId !== null && $ruleId > 0 && !isset($typesById[$ruleId])) {
            $ruleId = null;
        }
        if ($ruleId === null && $ruleAbbr !== null && $ruleAbbr !== '') {
            $key = strtoupper(trim($ruleAbbr));
            if (isset($idsByAbbr[$key])) {
                $ruleId = $idsByAbbr[$key];
            }
        }

        $finalId = $ruleId;
        $signals = [];
        $changed = false;

        if ($finalId === null && $engineId !== null) {
            $finalId = $engineId;
            $signals[] = 'continuity:media type filled';
            $changed = true;
        } elseif (
            $finalId !== null
            && $engineId !== null
            && $finalId !== $engineId
        ) {
            $signals[] = 'continuity:media type conflict';
        }

        $mtAgree = filter_var($verdict['media_type_agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($mtAgree === false) {
            $signals[] = 'continuity:media type disputed';
        } elseif ($mtAgree === true) {
            $signals[] = 'continuity:media type confirmed';
        }

        $finalName = null;
        $finalAbbr = null;
        $folder = null;
        if ($finalId !== null && isset($typesById[$finalId])) {
            $finalName = (string) $typesById[$finalId]['name'];
            $finalAbbr = (string) $typesById[$finalId]['abbreviation'];
            $folder = (string) $typesById[$finalId]['folder_name'];
        } elseif ($ruleAbbr !== null && $ruleAbbr !== '') {
            $finalAbbr = trim($ruleAbbr);
        }

        return [
            'media_type_id'           => $finalId,
            'media_type_name'         => $finalName,
            'media_type_abbreviation' => $finalAbbr,
            'folder_name'             => $folder,
            'engine_id'               => $engineId,
            'engine_abbr'             => $engineAbbr,
            'signals'                 => $signals,
            'changed'                 => $changed,
        ];
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

        $rank = static fn (string $c): int => match (strtoupper($c)) {
            'HIGH' => 3,
            'MEDIUM' => 2,
            'LOW' => 1,
            default => 0, // UNEVALUATED / unknown
        };
        $fromRank = static fn (int $r): string => match (true) {
            $r >= 3 => 'HIGH',
            $r === 2 => 'MEDIUM',
            $r === 1 => 'LOW',
            default => 'UNEVALUATED',
        };

        if ($agree === true) {
            // Never raise above the pattern score; confirm only.
            // Unevaluated pattern score: adopt the engine's assessed level.
            if (strtoupper($ruleConfidence) === 'UNEVALUATED') {
                return [
                    'confidence'    => $engineConf,
                    'adopt_show_id' => null,
                    'signal'        => 'continuity:confirmed',
                ];
            }

            return [
                'confidence'    => $fromRank(min($rank($ruleConfidence), $rank($engineConf))),
                'adopt_show_id' => null,
                'signal'        => 'continuity:confirmed',
            ];
        }

        if ($agree === false) {
            // Disputed mappings drop hard — especially false HIGHs.
            $final = strtoupper($ruleConfidence) === 'UNEVALUATED' ? 'UNEVALUATED' : 'LOW';

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
        $mediaTypes = $this->mediaTypeList();
        $timeline = $this->timelineFor($result->fileDate, $result->fileTime);
        $examples = $this->approvedExemplars();

        $system = <<<'PROMPT'
You are a broadcast archive continuity checker for NewsNation media files.
Decide whether the proposed show mapping is consistent with the dictionary, air schedule, and approved examples.
Also extract air date/time and media type (Clean vs Program, etc.) from the original filename/path when possible.
Rules:
- Choose show_id ONLY from the provided shows list, or null.
- Choose media_type_id ONLY from the provided media_types list, or null.
- Prefer schedule alignment when date/time are present.
- Short or ambiguous path tokens are weak evidence — do not over-trust them.
- Never invent show abbreviations, media type abbreviations, or filenames.
- file_date must be YYYYMMDD or null. file_time must be HHMM (24h Eastern) or null.
- datetime_agree is true when proposed file_date/file_time look correct for the filename/path.
- If proposal date/time are missing but filename clearly has them, fill file_date/file_time.
- media_type_agree is true when proposed media type looks correct for the filename/path.
- If proposal media type is missing but filename/path clearly indicates Clean, Program, GISO, etc., fill media_type_id.
- Respond with JSON only, no prose:
{"agree":true|false,"confidence":"HIGH"|"MEDIUM"|"LOW","show_id":null|number,"media_type_id":null|number,"media_type_agree":true|false,"file_date":null|string,"file_time":null|string,"datetime_agree":true|false,"reason":"short"}
PROMPT;

        $seedPacket = [
            'original_path'     => $originalPath,
            'original_filename' => $originalFilename,
            'proposal'          => [
                'show_id'           => $result->showId,
                'show_abbreviation' => $result->showAbbreviation,
                'media_type_id'     => $result->mediaTypeId,
                'media_type'        => $result->mediaTypeAbbreviation,
                'file_date'         => $result->fileDate,
                'file_time'         => $result->fileTime,
                'proposed_dir'      => $result->proposedDir,
                'proposed_filename' => $result->proposedFilename,
                'confidence'        => $result->confidence,
                'signals'           => $result->signals,
            ],
            'shows'        => $shows,
            'media_types'  => $mediaTypes,
            'timeline'     => $timeline,
            'examples'     => $examples,
            'system'       => $system,
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
            'media_types' => $mediaTypes,
            'timeline' => $timeline,
            'examples' => array_map(static function (array $ex): array {
                return [
                    'original_filename'  => $ex['original_filename'] ?? '',
                    'proposed_filename'  => $ex['proposed_filename'] ?? '',
                    'show_abbr'          => $ex['show_abbr'] ?? '',
                    'media_type_abbr'    => $ex['media_type_abbr'] ?? null,
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
        $dt = self::mergeDateTime($result->fileDate, $result->fileTime, $verdict);
        $mt = $this->mergeMediaTypeFor($result, $verdict);
        $signals = $result->signals;
        $signals[] = $merged['signal'];
        foreach ($dt['signals'] as $dtSignal) {
            $signals[] = $dtSignal;
        }
        foreach ($mt['signals'] as $mtSignal) {
            $signals[] = $mtSignal;
        }
        $reason = trim((string) ($verdict['reason'] ?? ''));
        if ($reason !== '') {
            $signals[] = 'continuity:note ' . mb_substr($reason, 0, 160);
        }

        // Disputed datetime/media type on a HIGH show match — blunt confidence one step.
        if (
            (in_array('continuity:datetime disputed', $dt['signals'], true)
                || in_array('continuity:date conflict', $dt['signals'], true))
            && $merged['confidence'] === 'HIGH'
        ) {
            $merged['confidence'] = 'MEDIUM';
            $signals[] = 'continuity:confidence blunted for datetime';
        }
        if (
            (in_array('continuity:media type disputed', $mt['signals'], true)
                || in_array('continuity:media type conflict', $mt['signals'], true))
            && $merged['confidence'] === 'HIGH'
        ) {
            $merged['confidence'] = 'MEDIUM';
            $signals[] = 'continuity:confidence blunted for media type';
        }

        $showId = $result->showId;
        $showAbbr = $result->showAbbreviation;
        $fileDate = $dt['file_date'];
        $fileTime = $dt['file_time'];
        $mediaTypeId = $mt['media_type_id'];
        $mediaTypeName = $mt['media_type_name'];
        $mediaTypeAbbr = $mt['media_type_abbreviation'];
        $proposedDir = $result->proposedDir;
        $proposedFilename = $result->proposedFilename;
        $needsRebuild = $dt['changed'] || $mt['changed'];

        $adoptId = $merged['adopt_show_id'];
        if ($adoptId !== null && $adoptId !== $result->showId) {
            $show = $this->shows->findById($adoptId);
            if ($show !== null) {
                $showId = $adoptId;
                $showAbbr = (string) $show['abbreviation'];
                $signals[] = 'continuity:show adjusted';
                $needsRebuild = true;
            }
        }

        if (
            $needsRebuild
            && $showAbbr !== null
            && $showAbbr !== ''
            && $fileDate !== null
            && $mediaTypeAbbr !== null
            && $mediaTypeAbbr !== ''
        ) {
            $folder = $mt['folder_name'] !== null && $mt['folder_name'] !== ''
                ? (string) $mt['folder_name']
                : (string) $mediaTypeAbbr;
            $guest = ProposalPathBuilder::guestFromProposed($result->proposedFilename);
            $built = ProposalPathBuilder::build(
                $showAbbr,
                $fileDate,
                $fileTime,
                (string) $mediaTypeAbbr,
                $folder,
                $originalFilename,
                $guest
            );
            if ($built !== null) {
                $proposedDir = $built['proposed_dir'];
                $proposedFilename = $built['proposed_filename'];
            }
        }

        return $result->withAdjustments(
            confidence: $merged['confidence'],
            signals: $signals,
            showId: $showId,
            showAbbreviation: $showAbbr,
            proposedDir: $proposedDir,
            proposedFilename: $proposedFilename,
            fileDate: $fileDate,
            fileTime: $fileTime,
            overrideDateTime: true,
            mediaTypeId: $mediaTypeId,
            mediaTypeName: $mediaTypeName,
            mediaTypeAbbreviation: $mediaTypeAbbr,
            overrideMediaType: true,
        );
    }

    /**
     * @param array<string, mixed> $verdict
     * @return array{
     *   media_type_id: ?int,
     *   media_type_name: ?string,
     *   media_type_abbreviation: ?string,
     *   folder_name: ?string,
     *   engine_id: ?int,
     *   engine_abbr: ?string,
     *   signals: list<string>,
     *   changed: bool
     * }
     */
    private function mergeMediaTypeFor(ClassifierResult $result, array $verdict): array
    {
        [$typesById, $idsByAbbr] = $this->mediaTypeIndexes();

        return self::mergeMediaType(
            $result->mediaTypeId,
            $result->mediaTypeAbbreviation,
            $verdict,
            $typesById,
            $idsByAbbr
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

    /** @return list<array{id: int, abbreviation: string, name: string, folder_name: string}> */
    private function mediaTypeList(): array
    {
        if ($this->mediaTypeCatalog !== null) {
            return $this->mediaTypeCatalog;
        }
        $out = [];
        foreach ($this->mediaTypes->all(true) as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'abbreviation' => (string) $row['abbreviation'],
                'name'         => (string) $row['name'],
                'folder_name'  => (string) ($row['folder_name'] ?? $row['name']),
            ];
        }
        $this->mediaTypeCatalog = $out;

        return $out;
    }

    /**
     * @return array{
     *   0: array<int, array{id: int, abbreviation: string, name: string, folder_name: string}>,
     *   1: array<string, int>
     * }
     */
    private function mediaTypeIndexes(): array
    {
        $typesById = [];
        $idsByAbbr = [];
        foreach ($this->mediaTypeList() as $row) {
            $id = (int) $row['id'];
            $typesById[$id] = $row;
            $abbr = strtoupper(trim((string) $row['abbreviation']));
            if ($abbr !== '') {
                $idsByAbbr[$abbr] = $id;
            }
        }

        return [$typesById, $idsByAbbr];
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
