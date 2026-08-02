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

$r = DateNormalizer::fromFilename('CUOMO 20221004 1850.ts');
assert_eq('20221004', $r['date'], 'space-separated date');
assert_eq('1850', $r['time'], 'space-separated time');

$r = DateNormalizer::fromFilename('SHOW202210041850.ts');
assert_eq('20221004', $r['date'], 'contiguous date');
assert_eq('1850', $r['time'], 'contiguous time');

$r = DateNormalizer::fromFilename('CUOMO_2022-10-03_1850.ts');
assert_eq('20221003', $r['date'], 'iso with time date');
assert_eq('1850', $r['time'], 'iso with time');

$r = DateNormalizer::fromFilename('clip_10-03-2022_19:00.mxf');
assert_eq('20221003', $r['date'], 'US date');
assert_eq('1900', $r['time'], 'US date time');

// Seagate / linear PGM feed: MMDDYY H{A|P} EST
$r = DateNormalizer::fromFilename('060625 8P EST.mp4', ['JUNE 2025']);
assert_eq('20250606', $r['date'], 'seagate 8P date');
assert_eq('2000', $r['time'], 'seagate 8P time');
assert_eq('filename:MMDDYY_H{A|P}_EST', $r['signal'], 'seagate signal');

$r = DateNormalizer::fromFilename('060625 7A EST.mxf', ['JUNE 2025']);
assert_eq('20250606', $r['date'], 'seagate 7A date');
assert_eq('0700', $r['time'], 'seagate 7A time');

$r = DateNormalizer::fromFilename('060625 12P EST.mp4', ['JUNE 2025']);
assert_eq('1200', $r['time'], 'seagate 12P noon');

$r = DateNormalizer::fromFilename('060625 12A EST.mp4', ['JUNE 2025']);
assert_eq('0000', $r['time'], 'seagate 12A midnight');

$r = DateNormalizer::fromFilename('121224 10P EST.mxf', ['DECEMBER 2024']);
assert_eq('20241212', $r['date'], 'seagate Dec 2024 date');
assert_eq('2200', $r['time'], 'seagate 10P time');

assert_eq('2000', DateNormalizer::hourApToHhmm(8, 'P'), 'hourAp 8P');
assert_eq('0800', DateNormalizer::hourApToHhmm(8, 'A'), 'hourAp 8A');
assert_eq(2025, DateNormalizer::expandTwoDigitYear(25, 2025), 'yy with hint');
assert_eq(2025, DateNormalizer::yearHintFromPath(['SEAGATE PGM FEED', 'JUNE 2025']), 'year hint JUNE 2025');

$path = DateNormalizer::fromPathSegments(['CUOMO', '2022', '10', '03', 'Clean']);
assert_eq('20221003', $path['date'], 'path YYYY/MM/DD');

$path = DateNormalizer::fromPathSegments(['cuomo', '2025', '12', 'CLEAN']);
assert_eq('20251201', $path['date'], 'path YYYY/MM defaults day');

assert_eq('1445', DateNormalizer::normalizeTime('1445'), 'normalize 4 digit time');
assert_eq('1850', DateNormalizer::normalizeTime('18502010'), 'normalize 8 digit time');
assert_eq(null, DateNormalizer::normalizeTime('9999'), 'invalid time rejected');

assert_eq(20 * 60, DateNormalizer::timeToMinutes('2000'), 'HHMM to minutes evening');
assert_eq(8 * 60 + 30, DateNormalizer::timeToMinutes('08:30'), 'HH:MM to minutes morning');
assert_eq('2000', DateNormalizer::minutesToHhmm(20 * 60), 'minutes to HHMM');

$merged = DateNormalizer::mergePathDate(null, ['cuomo', '2025', '12', 'CLEAN', 'file.ts']);
assert_eq('20251201', $merged, 'path year/month fallback');

echo "\nAll DateNormalizer tests passed.\n";
