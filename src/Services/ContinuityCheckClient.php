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
        private readonly int $timeoutSeconds = 8,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            rtrim((string) env('CONTINUITY_CHECK_URL', 'http://127.0.0.1:11434'), '/'),
            (string) env('CONTINUITY_CHECK_MODEL', 'llama3.2:3b'),
            max(2, (int) env('CONTINUITY_CHECK_TIMEOUT_SECONDS', 8)),
        );
    }

    public function isReachable(): bool
    {
        $ch = curl_init($this->baseUrl . '/api/tags');
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_HTTPGET        => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $body !== false && $code >= 200 && $code < 300;
    }

    /**
     * @return array<string, mixed>|null Decoded JSON object from the engine, or null on failure
     */
    public function completeJson(string $systemPrompt, string $userPrompt): ?array
    {
        $payload = [
            'model'   => $this->model,
            'stream'  => false,
            'format'  => 'json',
            'options' => [
                'temperature' => 0,
                'num_predict' => 256,
            ],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $ch = curl_init($this->baseUrl . '/api/chat');
        if ($ch === false) {
            return null;
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => min(3, $this->timeoutSeconds),
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) {
            return null;
        }

        try {
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $content = $envelope['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
