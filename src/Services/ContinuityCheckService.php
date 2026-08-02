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
 * Seeds the engine with the full show dictionary, full Timeline, day slots, and the
 * scan/catalog proposal; the model returns an independent recommendation plus
 * agree/conflict. Weak or disputed rule hits (and missing show) adopt the model.
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
    private ?array $scheduleCatalog = null;

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

    /** Parallel continuity HTTP requests during Scan/Reclassify (1–8). */
    public static function concurrency(): int
    {
        $n = (int) env('CONTINUITY_CHECK_CONCURRENCY', 4);

        return max(1, min(8, $n));
    }

    /** Whether refine() would call the engine (vs early-return). */
    public function willAskEngine(ClassifierResult $result): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        if ($result->policyExactMatch) {
            return false;
        }

        // Always ask when enabled (including no-show / LOW) so the model can recommend.
        return $this->engineAvailable();
    }

    public function refine(
        ClassifierResult $result,
        string $originalPath,
        string $originalFilename,
        ?int $fileId = null
    ): ClassifierResult {
        $batch = $this->refineBatch([[
            'result'            => $result,
            'original_path'     => $originalPath,
            'original_filename' => $originalFilename,
            'file_id'           => $fileId,
        ]]);

        return $batch[0] ?? $result;
    }

    /**
     * Refine many classifier results with concurrent engine requests.
     *
     * @param list<array{
     *   result: ClassifierResult,
     *   original_path: string,
     *   original_filename: string,
     *   file_id?: ?int
     * }> $items
     * @return list<ClassifierResult>
     */
    public function refineBatch(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $out = [];
        /** @var list<array{index: int, result: ClassifierResult, path: string, filename: string, file_id: ?int, system: string, user: string, seed_packet: array<string, mixed>}> $pending */
        $pending = [];

        foreach ($items as $i => $item) {
            $result = $item['result'];
            $path = (string) $item['original_path'];
            $filename = (string) $item['original_filename'];
            $fileId = isset($item['file_id']) ? (int) $item['file_id'] : null;
            if ($fileId !== null && $fileId <= 0) {
                $fileId = null;
            }

            if (!$this->willAskEngine($result)) {
                $out[$i] = $result;
                continue;
            }

            try {
                $built = $this->buildEngineRequest($result, $path, $filename);
            } catch (\Throwable $e) {
                error_log('[continuity] build request failed: ' . $e->getMessage());
                $out[$i] = $result;
                continue;
            }

            $pending[] = [
                'index'       => $i,
                'result'      => $result,
                'path'        => $path,
                'filename'    => $filename,
                'file_id'     => $fileId,
                'system'      => $built['system'],
                'user'        => $built['user'],
                'seed_packet' => $built['seed_packet'],
            ];
        }

        if ($pending !== []) {
            $chunkSize = self::concurrency();
            foreach (array_chunk($pending, $chunkSize) as $chunk) {
                $started = microtime(true);
                $httpRequests = array_map(static fn (array $p): array => [
                    'system' => $p['system'],
                    'user'   => $p['user'],
                ], $chunk);
                $responses = $this->client->completeJsonMany($httpRequests);
                $batchMs = (int) round((microtime(true) - $started) * 1000);
                $perMs = (int) max(1, (int) round($batchMs / max(1, count($chunk))));

                /** @var list<array{pending: array<string, mixed>, response: array<string, mixed>, ms: int}> $needRepair */
                $needRepair = [];
                foreach ($chunk as $j => $p) {
                    $response = $responses[$j] ?? [
                        'verdict'          => null,
                        'raw_content'      => '',
                        'http_status'      => null,
                        'transport_error'  => 'missing parallel response',
                        'transport_failed' => true,
                    ];
                    if ($response['verdict'] === null && !empty($response['transport_failed'])) {
                        $this->reachable = false;
                        $this->reachableCheckedAt = microtime(true);
                    }

                    $proposal = is_array($p['seed_packet']['proposal'] ?? null)
                        ? $p['seed_packet']['proposal']
                        : [];
                    $verdict = is_array($response['verdict'] ?? null)
                        ? self::normalizeVerdict($response['verdict'])
                        : null;
                    if (
                        $verdict !== null
                        && self::verdictIncomplete($verdict, $proposal)
                    ) {
                        $needRepair[] = [
                            'pending'  => $p,
                            'response' => $response + ['verdict' => $verdict],
                            'ms'       => $perMs,
                        ];
                        continue;
                    }

                    $out[$p['index']] = $this->settleAsk(
                        $p['result'],
                        $p['path'],
                        $p['filename'],
                        $p['file_id'],
                        [
                            'verdict'         => $verdict,
                            'seed_packet'     => $p['seed_packet'],
                            'raw_content'     => $response['raw_content'],
                            'http_status'     => $response['http_status'],
                            'transport_error' => $response['transport_error'],
                        ],
                        $perMs
                    );
                }

                if ($needRepair !== []) {
                    $repairStarted = microtime(true);
                    $repairRequests = [];
                    foreach ($needRepair as $item) {
                        $prior = $item['response']['verdict'] ?? [];
                        $repairRequests[] = [
                            'system' => 'Your previous JSON omitted required fields. Reply with complete JSON only: '
                                . 'agree, confidence (HIGH|MEDIUM|LOW), show_id, media_type_id, media_type_agree, '
                                . 'file_date (YYYYMMDD), file_time (HHMM), datetime_agree, reason. '
                                . 'Mirror proposal file_date/file_time/media_type_id when you do not dispute them. '
                                . 'Choose show_id and media_type_id only from the catalogs in the user JSON.',
                            'user'   => (string) json_encode([
                                'repair'   => true,
                                'previous' => $prior,
                                'context'  => json_decode($item['pending']['user'], true),
                            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        ];
                    }
                    $repairResponses = $this->client->completeJsonMany($repairRequests);
                    $repairBatchMs = (int) round((microtime(true) - $repairStarted) * 1000);
                    $repairPerMs = (int) max(1, (int) round($repairBatchMs / max(1, count($needRepair))));

                    foreach ($needRepair as $j => $item) {
                        $p = $item['pending'];
                        $first = $item['response'];
                        $second = $repairResponses[$j] ?? [
                            'verdict'          => null,
                            'raw_content'      => '',
                            'http_status'      => null,
                            'transport_error'  => 'missing repair response',
                            'transport_failed' => true,
                        ];
                        if ($second['verdict'] === null && !empty($second['transport_failed'])) {
                            $this->reachable = false;
                            $this->reachableCheckedAt = microtime(true);
                        }

                        $verdict = null;
                        if (is_array($second['verdict'] ?? null)) {
                            $verdict = self::normalizeVerdict($second['verdict']);
                        } elseif (is_array($first['verdict'] ?? null)) {
                            $verdict = self::normalizeVerdict($first['verdict']);
                        }

                        $raw = trim((string) ($first['raw_content'] ?? ''));
                        $raw2 = trim((string) ($second['raw_content'] ?? ''));
                        if ($raw2 !== '') {
                            $raw = $raw === '' ? $raw2 : ($raw . "\n--- repair ---\n" . $raw2);
                        }
                        $transport = trim((string) ($second['transport_error'] ?? ''));
                        if ($transport === '') {
                            $transport = (string) ($first['transport_error'] ?? '');
                        }
                        $httpStatus = $second['http_status'] ?? $first['http_status'] ?? null;

                        $out[$p['index']] = $this->settleAsk(
                            $p['result'],
                            $p['path'],
                            $p['filename'],
                            $p['file_id'],
                            [
                                'verdict'         => $verdict,
                                'seed_packet'     => $p['seed_packet'],
                                'raw_content'     => $raw,
                                'http_status'     => $httpStatus,
                                'transport_error' => $transport,
                            ],
                            $item['ms'] + $repairPerMs
                        );
                    }
                }
            }
        }

        ksort($out);

        return array_values($out);
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
     * Build a policy-shaped proposed filename from classification parts.
     * Used for engine logging and Continuity Lab display reconstruction.
     */
    public static function buildProposedFilename(
        string $originalFilename,
        ?string $showAbbr,
        ?string $fileDate,
        ?string $fileTime,
        ?string $mediaTypeAbbr,
        ?string $folderName = null,
        ?string $guestName = null
    ): ?string {
        $showAbbr = $showAbbr !== null ? trim($showAbbr) : '';
        $fileDate = $fileDate !== null ? trim($fileDate) : '';
        $mediaTypeAbbr = $mediaTypeAbbr !== null ? trim($mediaTypeAbbr) : '';
        if ($showAbbr === '' || $fileDate === '' || $mediaTypeAbbr === '') {
            return null;
        }
        $folder = $folderName !== null && trim($folderName) !== '' ? trim($folderName) : $mediaTypeAbbr;
        $built = ProposalPathBuilder::build(
            $showAbbr,
            $fileDate,
            $fileTime,
            $mediaTypeAbbr,
            $folder,
            $originalFilename,
            $guestName
        );

        return $built['proposed_filename'] ?? null;
    }

    /**
     * Prefer the model's proposed parts when rules are weak or the model disputes them.
     *
     * @param array<string, mixed> $verdict
     */
    public static function preferEngineProposal(string $ruleConfidence, array $verdict): bool
    {
        $agree = filter_var($verdict['agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($agree === false) {
            return true;
        }

        return in_array(strtoupper(trim($ruleConfidence)), ['LOW', 'UNEVALUATED'], true);
    }

    /**
     * Coerce common model quirks into the expected verdict shape.
     *
     * @param array<string, mixed> $verdict
     * @return array<string, mixed>
     */
    public static function normalizeVerdict(array $verdict): array
    {
        $conf = strtoupper(trim((string) ($verdict['confidence'] ?? '')));
        if (in_array($conf, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            $verdict['confidence'] = $conf;
        } else {
            unset($verdict['confidence']);
        }

        if (array_key_exists('show_id', $verdict)) {
            if ($verdict['show_id'] === null || $verdict['show_id'] === '') {
                $verdict['show_id'] = null;
            } elseif (is_numeric($verdict['show_id'])) {
                $id = (int) $verdict['show_id'];
                $verdict['show_id'] = $id > 0 ? $id : null;
            } else {
                $verdict['show_id'] = null;
            }
        }

        if (array_key_exists('media_type_id', $verdict)) {
            if ($verdict['media_type_id'] === null || $verdict['media_type_id'] === '') {
                $verdict['media_type_id'] = null;
            } elseif (is_numeric($verdict['media_type_id'])) {
                $id = (int) $verdict['media_type_id'];
                $verdict['media_type_id'] = $id > 0 ? $id : null;
            } else {
                // Model sometimes returns abbreviation in media_type_id.
                $verdict['media_type'] = (string) $verdict['media_type_id'];
                $verdict['media_type_id'] = null;
            }
        }

        if (isset($verdict['file_date']) && $verdict['file_date'] !== null && $verdict['file_date'] !== '') {
            $norm = ProposalPathBuilder::normalizeDateInput((string) $verdict['file_date']);
            $verdict['file_date'] = $norm;
        } elseif (array_key_exists('file_date', $verdict)) {
            $verdict['file_date'] = null;
        }

        if (isset($verdict['file_time']) && $verdict['file_time'] !== null && $verdict['file_time'] !== '') {
            $verdict['file_time'] = DateNormalizer::normalizeTime((string) $verdict['file_time']);
        } elseif (array_key_exists('file_time', $verdict)) {
            $verdict['file_time'] = null;
        }

        return $verdict;
    }

    /**
     * True when the model omitted required proposal fields (triggers one repair retry).
     *
     * @param array<string, mixed> $verdict
     * @param array<string, mixed> $proposal
     */
    public static function verdictIncomplete(array $verdict, array $proposal): bool
    {
        $conf = strtoupper(trim((string) ($verdict['confidence'] ?? '')));
        if (!in_array($conf, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            return true;
        }

        $propDate = trim((string) ($proposal['file_date'] ?? ''));
        $propTime = trim((string) ($proposal['file_time'] ?? ''));
        $propTypeId = $proposal['media_type_id'] ?? null;
        $propShowId = $proposal['show_id'] ?? null;

        $engDate = trim((string) ($verdict['file_date'] ?? ''));
        $engTime = trim((string) ($verdict['file_time'] ?? ''));
        $engType = $verdict['media_type_id'] ?? null;
        if (($engType === null || $engType === '') && trim((string) ($verdict['media_type'] ?? '')) === '') {
            $engType = null;
        }
        $engShow = $verdict['show_id'] ?? null;

        if ($propDate !== '' && $engDate === '') {
            return true;
        }
        if ($propTime !== '' && $engTime === '') {
            return true;
        }
        if ($propTypeId !== null && $propTypeId !== '' && ($engType === null || $engType === '')) {
            return true;
        }

        $agree = filter_var($verdict['agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($agree === false && ($engShow === null || $engShow === '' || (int) $engShow <= 0)) {
            return true;
        }
        if (
            $agree === true
            && $propShowId !== null
            && $propShowId !== ''
            && ($engShow === null || $engShow === '' || (int) $engShow <= 0)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Fill null model fields from the rule proposal after normalize/retry.
     * Date/time/type are mirrored whenever the proposal has them; show only when agree≠false.
     *
     * @param array<string, mixed> $verdict
     * @param array<string, mixed> $proposal
     * @return array{verdict: array<string, mixed>, filled: list<string>}
     */
    public static function completeVerdictFromProposal(array $verdict, array $proposal): array
    {
        $filled = [];
        $conf = strtoupper(trim((string) ($verdict['confidence'] ?? '')));
        if (!in_array($conf, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            $verdict['confidence'] = 'MEDIUM';
            $filled[] = 'confidence';
        }

        $agree = filter_var($verdict['agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (
            ($verdict['file_date'] ?? null) === null
            || $verdict['file_date'] === ''
        ) {
            $propDate = trim((string) ($proposal['file_date'] ?? ''));
            if ($propDate !== '') {
                $norm = ProposalPathBuilder::normalizeDateInput($propDate);
                if ($norm !== null) {
                    $verdict['file_date'] = $norm;
                    $filled[] = 'file_date';
                }
            }
        }

        if (
            ($verdict['file_time'] ?? null) === null
            || $verdict['file_time'] === ''
        ) {
            $propTime = trim((string) ($proposal['file_time'] ?? ''));
            if ($propTime !== '') {
                $norm = DateNormalizer::normalizeTime($propTime);
                if ($norm !== null) {
                    $verdict['file_time'] = $norm;
                    $filled[] = 'file_time';
                }
            }
        }

        $hasType = isset($verdict['media_type_id']) && is_numeric($verdict['media_type_id'])
            && (int) $verdict['media_type_id'] > 0;
        if (!$hasType && trim((string) ($verdict['media_type'] ?? '')) === '') {
            if (isset($proposal['media_type_id']) && is_numeric($proposal['media_type_id'])) {
                $verdict['media_type_id'] = (int) $proposal['media_type_id'];
                $filled[] = 'media_type_id';
            } elseif (trim((string) ($proposal['media_type'] ?? '')) !== '') {
                $verdict['media_type'] = (string) $proposal['media_type'];
                $filled[] = 'media_type';
            }
        }

        $hasShow = isset($verdict['show_id']) && is_numeric($verdict['show_id'])
            && (int) $verdict['show_id'] > 0;
        if (!$hasShow && $agree !== false && isset($proposal['show_id']) && is_numeric($proposal['show_id'])) {
            $sid = (int) $proposal['show_id'];
            if ($sid > 0) {
                $verdict['show_id'] = $sid;
                $filled[] = 'show_id';
            }
        }

        if ($filled !== []) {
            $note = 'filled:' . implode(',', $filled);
            $reason = trim((string) ($verdict['reason'] ?? ''));
            $verdict['reason'] = $reason === '' ? $note : ($reason . ' [' . $note . ']');
        }

        return ['verdict' => $verdict, 'filled' => $filled];
    }

    /**
     * Prefer schedule rows matching the air day / nearby hour (keeps the prompt small).
     *
     * @param list<array<string, mixed>> $schedule
     * @return list<array<string, mixed>>
     */
    public static function leanSchedule(array $schedule, ?string $dateYmd, ?string $timeHhmm, int $limit = 36): array
    {
        if (count($schedule) <= $limit) {
            return $schedule;
        }

        $dayBit = 0;
        if ($dateYmd !== null && DateNormalizer::isValidDate($dateYmd)) {
            $dayBit = ScheduleTimeParser::dayBitFromDate($dateYmd);
        }
        $targetMin = DateNormalizer::timeToMinutes($timeHhmm) ?? (12 * 60);

        $scored = [];
        foreach ($schedule as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $score = 0;
            $days = (int) ($row['days'] ?? 0);
            if ($dayBit > 0 && ($days & $dayBit) !== 0) {
                $score += 20;
            }
            $start = (string) ($row['start'] ?? '');
            if (preg_match('/^(\d{2}):(\d{2})/', $start, $m) === 1) {
                $startMin = ((int) $m[1] * 60) + (int) $m[2];
                $dist = abs($startMin - $targetMin);
                $score += max(0, 12 - (int) floor($dist / 60));
            }
            // Prefer rows effective on the air date when known.
            if ($dateYmd !== null && DateNormalizer::isValidDate($dateYmd)) {
                $from = (string) ($row['from'] ?? '');
                $to = $row['to'] ?? null;
                $iso = substr($dateYmd, 0, 4) . '-' . substr($dateYmd, 4, 2) . '-' . substr($dateYmd, 6, 2);
                if ($from !== '' && $from <= $iso && ($to === null || $to === '' || (string) $to >= $iso)) {
                    $score += 8;
                }
            }
            $scored[] = ['score' => $score, 'idx' => $idx, 'row' => $row];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        $out = [];
        foreach (array_slice($scored, 0, $limit) as $item) {
            $out[] = $item['row'];
        }

        return $out;
    }

    /**
     * Merge engine date/time with rule values.
     * Fills gaps; adopts model on weak/disputed rules; keeps strong rule hits on conflict.
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
    public static function mergeDateTime(
        ?string $ruleDate,
        ?string $ruleTime,
        array $verdict,
        string $ruleConfidence = 'MEDIUM'
    ): array {
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
        $preferEngine = self::preferEngineProposal($ruleConfidence, $verdict);
        $dtAgree = filter_var($verdict['datetime_agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $adoptOnConflict = $preferEngine || $dtAgree === false;

        if ($finalDate === null && $engineDate !== null) {
            $finalDate = $engineDate;
            $signals[] = 'continuity:date filled';
            $changed = true;
        } elseif (
            $finalDate !== null
            && $engineDate !== null
            && $finalDate !== $engineDate
        ) {
            if ($adoptOnConflict) {
                $finalDate = $engineDate;
                $signals[] = 'continuity:date adopted';
                $changed = true;
            } else {
                $signals[] = 'continuity:date conflict';
            }
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
            if ($adoptOnConflict) {
                $finalTime = $engineTime;
                $signals[] = 'continuity:time adopted';
                $changed = true;
            } else {
                $signals[] = 'continuity:time conflict';
            }
        }

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
     * Merge engine media type with rule values.
     * Fills gaps; adopts model on weak/disputed rules; keeps strong rule hits on conflict.
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
        array $idsByAbbr,
        string $ruleConfidence = 'MEDIUM'
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
                $engineId = self::lookupMediaTypeId((string) $rawAbbr, $idsByAbbr);
            }
        }

        $engineAbbr = $engineId !== null ? (string) $typesById[$engineId]['abbreviation'] : null;

        if ($ruleId !== null && $ruleId > 0 && !isset($typesById[$ruleId])) {
            $ruleId = null;
        }
        if ($ruleId === null && $ruleAbbr !== null && $ruleAbbr !== '') {
            $ruleId = self::lookupMediaTypeId($ruleAbbr, $idsByAbbr);
        }

        $finalId = $ruleId;
        $signals = [];
        $changed = false;
        $preferEngine = self::preferEngineProposal($ruleConfidence, $verdict);
        $mtAgree = filter_var($verdict['media_type_agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $adoptOnConflict = $preferEngine || $mtAgree === false;

        if ($finalId === null && $engineId !== null) {
            $finalId = $engineId;
            $signals[] = 'continuity:media type filled';
            $changed = true;
        } elseif (
            $finalId !== null
            && $engineId !== null
            && $finalId !== $engineId
        ) {
            if ($adoptOnConflict) {
                $finalId = $engineId;
                $signals[] = 'continuity:media type adopted';
                $changed = true;
            } else {
                $signals[] = 'continuity:media type conflict';
            }
        }

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

    /** @param array<string, int> $idsByAbbr */
    private static function lookupMediaTypeId(string $raw, array $idsByAbbr): ?int
    {
        foreach ([strtoupper(trim($raw)), Classifier::normalizeMediaTypeToken($raw)] as $key) {
            if ($key !== '' && isset($idsByAbbr[$key])) {
                return $idsByAbbr[$key];
            }
        }

        return null;
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

        $ruleConf = strtoupper(trim($ruleConfidence));

        if ($agree === true) {
            // Never raise above the pattern score; confirm only.
            // Weak rule hits: adopt the model's proposed show_id when present.
            if ($ruleConf === 'UNEVALUATED') {
                return [
                    'confidence'    => $engineConf,
                    'adopt_show_id' => $adoptShowId,
                    'signal'        => 'continuity:confirmed',
                ];
            }
            if ($ruleConf === 'LOW') {
                return [
                    'confidence'    => $fromRank(min($rank('LOW'), $rank($engineConf))),
                    'adopt_show_id' => $adoptShowId,
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
            $final = $ruleConf === 'UNEVALUATED' ? 'UNEVALUATED' : 'LOW';

            return [
                'confidence'    => $final,
                'adopt_show_id' => $adoptShowId,
                'signal'        => 'continuity:conflict',
            ];
        }

        // Uncertain / missing agree — blunt HIGH; adopt show when rules are weak.
        $adoptOnReview = in_array($ruleConf, ['LOW', 'UNEVALUATED'], true) ? $adoptShowId : null;

        return [
            'confidence'    => $ruleConf === 'HIGH' ? 'MEDIUM' : $ruleConfidence,
            'adopt_show_id' => $adoptOnReview,
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
     * @return array{system: string, user: string, seed_packet: array<string, mixed>}
     */
    private function buildEngineRequest(
        ClassifierResult $result,
        string $originalPath,
        string $originalFilename
    ): array {
        $shows = $this->catalog();
        $mediaTypes = $this->mediaTypeList();
        $schedule = $this->scheduleCatalog();
        $daySlots = $this->daySlotsFor($result->fileDate);
        $atAirTime = $this->slotsAtAirTime($daySlots, $result->fileTime);
        $examples = $this->approvedExemplars();

        $system = <<<'PROMPT'
You are a broadcast archive continuity reviewer for NewsNation media files.
You receive the FULL show dictionary, FULL program schedule (Timeline), every timeslot for the air date, approved examples, and the scan/catalog proposal (what rules already determined).
Your job:
1) Form your OWN recommendation for show, air date/time, and media type (as a human editor would).
2) Score your confidence (HIGH|MEDIUM|LOW).
3) Compare to catalog_proposal and set agree true/false (conflict when you disagree on show or overall mapping).
The application builds the policy filename from your fields — do not invent filenames.
Rules:
- ALWAYS return confidence, show_id, file_date, file_time, and media_type_id. Never leave them null when the filename/path or catalogs make them clear. If catalog_proposal is correct, mirror its values and set agree=true.
- Choose show_id ONLY from shows[].id. Choose media_type_id ONLY from media_types[].id (numeric).
- Use schedule[] (full Timeline) and day_slots[] (all blocks active on the air date) as the authority for what can air when. schedule.to null means the block is still current.
- at_air_time[] is the subset of day_slots matching the proposal air time — prefer it when non-empty.
- Path folders like PGM/Program/Clean/GISO are strong media-type evidence.
- Path/filename show tokens can be wrong or ambiguous — prefer schedule alignment over weak tokens (e.g. "NOW WEEKEND" is not Morning in America weekday).
- file_date = YYYYMMDD or null. file_time = HHMM Eastern or null.
- datetime_agree / media_type_agree reflect whether YOUR date/time and type look correct for the file.
- reason: short justification (mention schedule match or conflict when relevant).
- Respond with JSON only, no prose:
{"agree":true|false,"confidence":"HIGH"|"MEDIUM"|"LOW","show_id":null|number,"media_type_id":null|number,"media_type_agree":true|false,"file_date":null|string,"file_time":null|string,"datetime_agree":true|false,"reason":"short"}
PROMPT;

        $catalogProposal = [
            'source'              => 'scan_catalog_rules',
            'show_id'             => $result->showId,
            'show_abbreviation'   => $result->showAbbreviation,
            'media_type_id'       => $result->mediaTypeId,
            'media_type'          => $result->mediaTypeAbbreviation,
            'file_date'           => $result->fileDate,
            'file_time'           => $result->fileTime,
            'proposed_dir'        => $result->proposedDir,
            'proposed_filename'   => $result->proposedFilename,
            'confidence'          => $result->confidence,
            'policy_exact_match'  => $result->policyExactMatch,
            'signals'             => $result->signals,
        ];

        $showsPayload = array_map(static function (array $s): array {
            $aliases = $s['aliases'] ?? [];
            if (!is_array($aliases)) {
                $aliases = [];
            }

            return [
                'id'             => $s['id'],
                'abbreviation'   => $s['abbreviation'],
                'canonical_name' => $s['canonical_name'],
                'aliases'        => array_values(array_filter($aliases, 'is_string')),
            ];
        }, $shows);

        $seedPacket = [
            'original_path'     => $originalPath,
            'original_filename' => $originalFilename,
            'catalog_proposal'  => $catalogProposal,
            'proposal'          => $catalogProposal, // Lab / backward-compatible alias
            'shows'             => $showsPayload,
            'media_types'       => $mediaTypes,
            'schedule'          => $schedule,
            'schedule_count'    => count($schedule),
            'day_slots'         => $daySlots,
            'at_air_time'       => $atAirTime,
            'timeline'          => $daySlots,
            'examples'          => $examples,
            'system'            => $system,
        ];

        $userPayload = [
            'original_path'     => $originalPath,
            'original_filename' => $originalFilename,
            'catalog_proposal'  => $catalogProposal,
            'shows'             => $showsPayload,
            'media_types'       => $mediaTypes,
            'schedule'          => $schedule,
            'day_slots'         => $daySlots,
            'at_air_time'       => $atAirTime,
            'schedule_notes'    => [
                'to_null_means_current' => true,
                'days_bitmask'          => 'Mon=1 Tue=2 Wed=4 Thu=8 Fri=16 Sat=32 Sun=64',
                'schedule_is_complete'  => true,
                'day_slots_is_complete_for_air_date' => true,
                'schedule_count'        => count($schedule),
                'day_slots_count'       => count($daySlots),
            ],
            'examples' => array_map(static function (array $ex): array {
                return [
                    'original_filename'  => $ex['original_filename'] ?? '',
                    'original_path'      => $ex['original_path'] ?? '',
                    'proposed_filename'  => $ex['proposed_filename'] ?? '',
                    'proposed_dir'       => $ex['proposed_dir'] ?? null,
                    'show_id'            => $ex['show_id'] ?? null,
                    'show_abbr'          => $ex['show_abbr'] ?? '',
                    'media_type_id'      => $ex['media_type_id'] ?? null,
                    'media_type_abbr'    => $ex['media_type_abbr'] ?? null,
                    'file_date'          => $ex['file_date'] ?? null,
                    'file_time'          => $ex['file_time'] ?? null,
                ];
            }, $examples),
        ];

        return [
            'system'      => $system,
            'user'        => json_encode($userPayload, JSON_THROW_ON_ERROR),
            'seed_packet' => $seedPacket,
        ];
    }

    /**
     * @param array{
     *   verdict: ?array<string, mixed>,
     *   seed_packet: array<string, mixed>,
     *   raw_content: string,
     *   http_status: ?int,
     *   transport_error: string
     * } $asked
     */
    private function settleAsk(
        ClassifierResult $result,
        string $originalPath,
        string $originalFilename,
        ?int $fileId,
        array $asked,
        int $durationMs
    ): ClassifierResult {
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

        $verdict = self::normalizeVerdict($verdict);
        $proposal = is_array($asked['seed_packet']['proposal'] ?? null)
            ? $asked['seed_packet']['proposal']
            : [];
        $completed = self::completeVerdictFromProposal($verdict, $proposal);
        $verdict = $completed['verdict'];
        $filledFromProposal = $completed['filled'];

        $adjusted = $this->applyVerdict($result, $verdict, $originalFilename);
        $merged = self::mergeVerdict($result->confidence, $verdict);
        $dt = self::mergeDateTime($result->fileDate, $result->fileTime, $verdict, $result->confidence);
        $mt = $this->mergeMediaTypeFor($result, $verdict);
        $agree = filter_var($verdict['agree'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $outcome = match ($merged['signal']) {
            'continuity:confirmed' => 'confirmed',
            'continuity:conflict'  => 'conflict',
            default                => 'review',
        };

        $engineShowId = isset($verdict['show_id']) && is_numeric($verdict['show_id'])
            ? (int) $verdict['show_id'] : null;
        if ($engineShowId !== null && $engineShowId <= 0) {
            $engineShowId = null;
        }
        $engineShowAbbr = null;
        if ($engineShowId !== null) {
            foreach ($this->catalog() as $showRow) {
                if ((int) ($showRow['id'] ?? 0) === $engineShowId) {
                    $engineShowAbbr = (string) ($showRow['abbreviation'] ?? '');
                    break;
                }
            }
            if ($engineShowAbbr === '') {
                $engineShowAbbr = null;
            }
        }

        // Model filename from engine fields (including proposal-filled gaps after repair).
        $modelFolder = $mt['engine_abbr'];
        if ($mt['engine_id'] !== null) {
            [$typesById] = $this->mediaTypeIndexes();
            $typeRow = $typesById[(int) $mt['engine_id']] ?? null;
            if (is_array($typeRow) && trim((string) ($typeRow['folder_name'] ?? '')) !== '') {
                $modelFolder = (string) $typeRow['folder_name'];
            }
        }
        $engineProposed = self::buildProposedFilename(
            $originalFilename,
            $engineShowAbbr,
            $dt['engine_date'],
            $dt['engine_time'],
            $mt['engine_abbr'],
            $modelFolder,
            ProposalPathBuilder::guestFromProposed($result->proposedFilename)
        );

        $engineReason = trim((string) ($verdict['reason'] ?? ''));
        if ($filledFromProposal !== [] && !str_contains($engineReason, 'filled:')) {
            $engineReason = trim($engineReason . ' [filled:' . implode(',', $filledFromProposal) . ']');
        }

        $this->safeLog($baseLog + [
            'engine_agree'              => $agree,
            'engine_confidence'         => isset($verdict['confidence']) ? strtoupper((string) $verdict['confidence']) : null,
            'engine_show_id'            => $engineShowId,
            'engine_file_date'          => $dt['engine_date'],
            'engine_file_time'          => $dt['engine_time'],
            'engine_media_type_id'      => $mt['engine_id'],
            'engine_media_type_abbr'    => $mt['engine_abbr'],
            'engine_proposed_filename'  => $engineProposed,
            'engine_reason'             => $engineReason,
            'final_confidence'          => $adjusted->confidence,
            'final_show_id'             => $adjusted->showId,
            'final_show_abbr'           => $adjusted->showAbbreviation,
            'final_proposed_filename'   => $adjusted->proposedFilename,
            'final_file_date'           => $adjusted->fileDate,
            'final_file_time'           => $adjusted->fileTime,
            'final_media_type_id'       => $adjusted->mediaTypeId,
            'final_media_type_abbr'     => $adjusted->mediaTypeAbbreviation,
            'signal'                    => $merged['signal'],
            'outcome'                   => $outcome,
        ]);

        return $adjusted;
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
        $dt = self::mergeDateTime($result->fileDate, $result->fileTime, $verdict, $result->confidence);
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
                || in_array('continuity:date conflict', $dt['signals'], true)
                || in_array('continuity:date adopted', $dt['signals'], true)
                || in_array('continuity:time adopted', $dt['signals'], true))
            && $merged['confidence'] === 'HIGH'
        ) {
            $merged['confidence'] = 'MEDIUM';
            $signals[] = 'continuity:confidence blunted for datetime';
        }
        if (
            (in_array('continuity:media type disputed', $mt['signals'], true)
                || in_array('continuity:media type conflict', $mt['signals'], true)
                || in_array('continuity:media type adopted', $mt['signals'], true))
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
        // Catalog/scan had no show — always take a valid model recommendation.
        if (($adoptId === null || $adoptId <= 0) && $result->showId === null
            && isset($verdict['show_id']) && is_numeric($verdict['show_id'])
        ) {
            $candidate = (int) $verdict['show_id'];
            if ($candidate > 0) {
                $adoptId = $candidate;
            }
        }
        if ($adoptId !== null && $adoptId !== $result->showId) {
            $show = $this->shows->findById($adoptId);
            if ($show !== null) {
                $showId = $adoptId;
                $showAbbr = (string) $show['abbreviation'];
                $signals[] = 'continuity:show adjusted';
                $needsRebuild = true;
            }
        } elseif (
            $adoptId !== null
            && $adoptId === $result->showId
            && ($result->proposedFilename === null || $result->proposedFilename === '')
            && $fileDate !== null
            && $mediaTypeAbbr !== null
        ) {
            // Weak rules had the show but no built name — rebuild from model/final parts.
            $needsRebuild = true;
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
            $idsByAbbr,
            $result->confidence
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
            foreach ([$row['abbreviation'] ?? '', $row['name'] ?? '', $row['folder_name'] ?? ''] as $label) {
                $key = Classifier::normalizeMediaTypeToken((string) $label);
                if ($key !== '') {
                    $idsByAbbr[$key] = $id;
                }
            }
        }
        // Common shorthand not always stored as abbreviation.
        foreach ($typesById as $id => $row) {
            $canon = Classifier::normalizeMediaTypeToken((string) ($row['abbreviation'] ?? $row['name'] ?? ''));
            if ($canon === 'PROGRAM') {
                $idsByAbbr['PGM'] = $id;
            }
            if ($canon === 'CLEAN') {
                $idsByAbbr['CLN'] = $id;
            }
        }

        return [$typesById, $idsByAbbr];
    }

    /** @return list<array<string, mixed>> */
    private function scheduleCatalog(): array
    {
        if ($this->scheduleCatalog !== null) {
            return $this->scheduleCatalog;
        }
        $this->scheduleCatalog = $this->schedule->listAllLeanActive();

        return $this->scheduleCatalog;
    }

    /**
     * Every active Timeline block that applies on the air date (full day — not truncated).
     *
     * @return list<array<string, mixed>>
     */
    private function daySlotsFor(?string $dateYmd): array
    {
        if ($dateYmd === null || !DateNormalizer::isValidDate($dateYmd)) {
            return [];
        }
        $iso = substr($dateYmd, 0, 4) . '-' . substr($dateYmd, 4, 2) . '-' . substr($dateYmd, 6, 2);
        $dayBit = ScheduleTimeParser::dayBitFromDate($dateYmd);
        if ($dayBit === 0) {
            return [];
        }

        $out = [];
        foreach ($this->scheduleCatalog() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $from = (string) ($row['from'] ?? '');
            $to = $row['to'] ?? null;
            if ($from !== '' && $iso < $from) {
                continue;
            }
            if ($to !== null && $to !== '' && $iso > (string) $to) {
                continue;
            }
            $days = (int) ($row['days'] ?? 0);
            if (($days & $dayBit) === 0) {
                continue;
            }
            $out[] = [
                'show_id'   => (int) ($row['show_id'] ?? 0),
                'show_abbr' => (string) ($row['show_abbr'] ?? ''),
                'title'     => (string) ($row['title'] ?? ''),
                'start'     => (string) ($row['start'] ?? ''),
                'end'       => (string) ($row['end'] ?? ''),
                'hour'      => substr((string) ($row['start'] ?? ''), 0, 5),
                'days'      => $days,
                'from'      => $from,
                'to'        => $to !== null && $to !== '' ? (string) $to : null,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return [$a['start'], $a['show_abbr']] <=> [$b['start'], $b['show_abbr']];
        });

        return $out;
    }

    /**
     * day_slots whose hour range covers the proposal air time (full match list — not capped).
     *
     * @param list<array<string, mixed>> $daySlots
     * @return list<array<string, mixed>>
     */
    private function slotsAtAirTime(array $daySlots, ?string $timeHhmm): array
    {
        $minutes = DateNormalizer::timeToMinutes($timeHhmm);
        if ($minutes === null) {
            return [];
        }

        $out = [];
        foreach ($daySlots as $slot) {
            $start = DateNormalizer::timeToMinutes((string) ($slot['start'] ?? ''));
            $end = DateNormalizer::timeToMinutes((string) ($slot['end'] ?? ''));
            if ($start === null || $end === null) {
                continue;
            }
            if ($end === 0 && $start > 0) {
                $end = 24 * 60;
            }
            if ($minutes >= $start && $minutes < $end) {
                $out[] = $slot;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function approvedExemplars(): array
    {
        if ($this->exemplars !== null) {
            return $this->exemplars;
        }
        // Max allowed by FileRepository::continuityExemplars (no further app-side truncation).
        $this->exemplars = $this->files->continuityExemplars(40);

        return $this->exemplars;
    }
}
