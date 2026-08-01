<?php

declare(strict_types=1);

namespace MediaManager\Support;

/**
 * Crockford Base32 ULID (26 chars). Time-sortable, filesystem-safe.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $timeMs = null): string
    {
        $timeMs ??= (int) floor(microtime(true) * 1000);
        if ($timeMs < 0) {
            $timeMs = 0;
        }

        $time = '';
        $t = $timeMs;
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$t % 32] . $time;
            $t = intdiv($t, 32);
        }

        $entropy = self::encodeEntropy(random_bytes(10));

        return $time . $entropy;
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value);
    }

    public static function normalize(string $value): string
    {
        return strtoupper($value);
    }

    /**
     * Light directory shards from a ULID: aa/bb/cc.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function shards(string $ulid): array
    {
        $ulid = self::normalize($ulid);
        if (!self::isValid($ulid)) {
            throw new \InvalidArgumentException('Invalid ULID.');
        }

        return [
            substr($ulid, 0, 2),
            substr($ulid, 2, 2),
            substr($ulid, 4, 2),
        ];
    }

    /** Relative shard path: 01/J8/X9/01J8X9K2M3N4P5Q6R7S8T9U0V1 */
    public static function shardPath(string $ulid): string
    {
        $ulid = self::normalize($ulid);
        [$a, $b, $c] = self::shards($ulid);

        return $a . '/' . $b . '/' . $c . '/' . $ulid;
    }

    private static function encodeEntropy(string $bytes): string
    {
        // 10 bytes = 80 bits → 16 Crockford chars
        $value = 0;
        $bits = 0;
        $out = '';
        $len = strlen($bytes);

        for ($i = 0; $i < $len; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($value >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $out .= self::ALPHABET[($value << (5 - $bits)) & 31];
        }

        return substr($out, 0, 16);
    }
}
