<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use MediaManager\Services\DateNormalizer;

/** @param mixed $expected */
function assert_eq(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("FAIL {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo "OK {$label}\n";
}

// ── DateNormalizer ────────────────────────────────────────────
$r = DateNormalizer::fromFilename('CUOMO CLEAN_20221004_1850.ts');
assert_eq('20221004', $r['date'], 'underscore date');
assert_eq('1850', $r['time'], 'underscore time');

$r = DateNormalizer::fromFilename('CUOMO_20250919_18502010.ts');
assert_eq('20250919', $r['date'], 'long time date');
assert_eq('1850', $r['time'], 'long time truncated to HHMM');

$r = DateNormalizer::fromFilename('CUOMO PGM 2025-12-01 NYNEWOF1ENC006.mxf');
assert_eq('20251201', $r['date'], 'iso date in filename');
assert_eq(null, $r['time'], 'iso date no time');

$r = DateNormalizer::fromFilename('NN AC CUOMO CLEAN2022-10-03 ILCHIOF1ENC008.mxf');
assert_eq('20221003', $r['date'], 'embedded iso date');

assert_eq('1445', DateNormalizer::normalizeTime('1445'), 'normalize 4 digit time');
assert_eq('1850', DateNormalizer::normalizeTime('18502010'), 'normalize 8 digit time');
assert_eq(null, DateNormalizer::normalizeTime('9999'), 'invalid time rejected');

$merged = DateNormalizer::mergePathDate(null, ['cuomo', '2025', '12', 'CLEAN', 'file.ts']);
assert_eq('20251201', $merged, 'path year/month fallback');

echo "\nAll DateNormalizer tests passed.\n";
