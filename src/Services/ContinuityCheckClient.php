<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * HTTP client for the local broadcast continuity engine.
 */
final class ContinuityCheckClient
{
    public function __construct(
        private readonly string $baseUrl = 'http://127.0.0.1:11434',
        private readonly string $model = 'llama3.2:3b',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            rtrim((string) env('CONTINUITY_CHECK_URL', 'http://127.0.0.1:11434'), '/'),
            (string) env('CONTINUITY_CHECK_MODEL', 'llama3.2:3b'),
            max(5, (int) env('CONTINUITY_CHECK_TIMEOUT_SECONDS', 60)),
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function isReachable(): bool
    {
        return $this->probe()['reachable'];
    }

    /** @return array{reachable: bool, latency_ms: ?int, packs: list<string>} */
    public function probe(): array
    {
        $ch = curl_init($this->baseUrl . '/api/tags');
        if ($ch === false) {
            return ['reachable' => false, 'latency_ms' => null, 'packs' => []];
        }
        $started = microtime(true);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPGET        => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $body !== false && $code >= 200 && $code < 300;
        $packs = [];
        if ($ok && is_string($body)) {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['models']) && is_array($decoded['models'])) {
                    foreach ($decoded['models'] as $model) {
                        if (is_array($model) && isset($model['name'])) {
                            $packs[] = (string) $model['name'];
                        }
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return [
            'reachable'  => $ok,
            'latency_ms' => $ok ? (int) round((microtime(true) - $started) * 1000) : null,
            'packs'      => $packs,
        ];
    }

    /**
     * Minimal round-trip used by Continuity Lab "Test engine".
     *
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
        $probe = $this->probe();
        $started = microtime(true);
        $packLoaded = in_array($this->model, $probe['packs'], true)
            || $this->packNameMatches($this->model, $probe['packs']);

        $response = $this->completeJson(
            'Reply with JSON only: {"agree":true,"confidence":"HIGH","show_id":null,"reason":"lab-self-test"}',
            '{"ping":true}'
        );

        return [
            'ok'              => is_array($response['verdict']),
            'duration_ms'     => (int) round((microtime(true) - $started) * 1000),
            'verdict'         => $response['verdict'],
            'raw_content'     => $response['raw_content'],
            'http_status'     => $response['http_status'],
            'transport_error' => $response['transport_error'],
            'pack_loaded'     => $packLoaded,
            'packs'           => $probe['packs'],
        ];
    }

    /**
     * @return array{
     *   verdict: ?array<string, mixed>,
     *   raw_content: string,
     *   http_status: ?int,
     *   transport_error: string,
     *   transport_failed: bool
     * }
     */
    public function completeJson(string $systemPrompt, string $userPrompt): array
    {
        $empty = [
            'verdict'          => null,
            'raw_content'      => '',
            'http_status'      => null,
            'transport_error'  => '',
            'transport_failed' => true,
        ];

        $payload = [
            'model'   => $this->model,
            'stream'  => false,
            'format'  => 'json',
            'options' => [
                'temperature' => 0,
                'num_predict' => 512,
            ],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $ch = curl_init($this->baseUrl . '/api/chat');
        if ($ch === false) {
            return $empty + ['transport_error' => 'curl_init failed'];
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return $empty + ['transport_error' => 'payload encode failed: ' . $e->getMessage()];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $hint = $curlErr !== '' ? $curlErr : 'empty transport response';
            if (stripos($hint, 'timed out') !== false || stripos($hint, 'timeout') !== false) {
                $hint .= ' — raise CONTINUITY_CHECK_TIMEOUT_SECONDS (currently '
                    . $this->timeoutSeconds . 's) or use a smaller/faster pack';
            }

            return [
                'verdict'          => null,
                'raw_content'      => '',
                'http_status'      => $code > 0 ? $code : null,
                'transport_error'  => $hint,
                'transport_failed' => true,
            ];
        }

        $rawStr = (string) $raw;
        if ($code < 200 || $code >= 300) {
            $hint = 'HTTP ' . $code;
            if ($code === 404) {
                $hint .= ' — pack not found. Run: ollama pull ' . $this->model;
            }

            return [
                'verdict'          => null,
                'raw_content'      => mb_substr($rawStr, 0, 4000),
                'http_status'      => $code,
                'transport_error'  => $hint,
                'transport_failed' => true,
            ];
        }

        try {
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode($rawStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [
                'verdict'          => null,
                'raw_content'      => mb_substr($rawStr, 0, 4000),
                'http_status'      => $code,
                'transport_error'  => 'envelope JSON decode failed: ' . $e->getMessage(),
                'transport_failed' => false,
            ];
        }

        $content = $envelope['message']['content'] ?? null;

        // Some engine versions already return structured JSON for format=json.
        if (is_array($content)) {
            return [
                'verdict'          => $content,
                'raw_content'      => mb_substr(
                    (string) json_encode($content, JSON_UNESCAPED_SLASHES),
                    0,
                    8000
                ),
                'http_status'      => $code,
                'transport_error'  => '',
                'transport_failed' => false,
            ];
        }

        if (!is_string($content) || trim($content) === '') {
            $errField = $envelope['error'] ?? null;
            $hint = 'missing message.content in engine envelope';
            if (is_string($errField) && $errField !== '') {
                $hint = $errField;
            }

            return [
                'verdict'          => null,
                'raw_content'      => mb_substr($rawStr, 0, 4000),
                'http_status'      => $code,
                'transport_error'  => $hint,
                'transport_failed' => false,
            ];
        }

        $decoded = $this->decodeVerdictJson($content);
        if ($decoded === null) {
            return [
                'verdict'          => null,
                'raw_content'      => mb_substr($content, 0, 4000),
                'http_status'      => $code,
                'transport_error'  => 'verdict JSON decode failed',
                'transport_failed' => false,
            ];
        }

        return [
            'verdict'          => $decoded,
            'raw_content'      => mb_substr($content, 0, 8000),
            'http_status'      => $code,
            'transport_error'  => '',
            'transport_failed' => false,
        ];
    }

    /** @return array<string, mixed>|null */
    private function decodeVerdictJson(string $content): ?array
    {
        $trimmed = trim($content);
        // Strip markdown fences if the pack ignored format=json.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            // Fall through — try to find first {...} object.
        }

        if (preg_match('/\{.*\}/s', $trimmed, $m) === 1) {
            try {
                $decoded = json_decode($m[0], true, 512, JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** @param list<string> $packs */
    private function packNameMatches(string $wanted, array $packs): bool
    {
        $wanted = strtolower($wanted);
        foreach ($packs as $pack) {
            $pack = strtolower($pack);
            if ($pack === $wanted || str_starts_with($pack, $wanted) || str_starts_with($wanted, $pack)) {
                return true;
            }
        }

        return false;
    }
}
