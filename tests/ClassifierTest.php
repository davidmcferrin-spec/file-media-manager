<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use MediaManager\Services\Classifier;

/**
 * Lightweight classifier test using in-memory overrides via reflection-free stub mount.
 * Requires DB with migration 002 seeds (Cuomo show + conversion rules).
 */

/** @param mixed $expected */
function assert_eq(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("FAIL {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo "OK {$label}\n";
}

try {
    \MediaManager\Database::connection();
} catch (\Throwable $e) {
    echo "SKIP ClassifierTest — no database: " . $e->getMessage() . "\n";
    exit(0);
}

$classifier = new Classifier();
$mount = '/mnt-smb/SNSEVO-NYL';

$r = $classifier->classify(
    $mount . '/cuomo/2022/10/CLEAN/CUOMO CLEAN_20221004_1850.ts',
    $mount
);
assert_eq('CUOMO', $r->showAbbreviation, 'cuomo clean show');
assert_eq('Clean', $r->mediaTypeAbbreviation, 'cuomo clean type');
assert_eq('20221004', $r->fileDate, 'cuomo clean date');
assert_eq('1850', $r->fileTime, 'cuomo clean time');
assert_eq('CUOMO/2022/10/Clean', $r->proposedDir, 'cuomo clean dir');
assert_eq('CUOMO_20221004_1850_Clean.ts', $r->proposedFilename, 'cuomo clean filename');
assert_eq('HIGH', $r->confidence, 'cuomo clean confidence');

$r = $classifier->classify(
    $mount . '/cuomo/2025/09/CLEAN/CUOMO TUESDAY PRETAPE_20250909_1445.ts',
    $mount
);
assert_eq('Clean', $r->mediaTypeAbbreviation, 'pretape maps to clean');
assert_eq('20250909', $r->fileDate, 'pretape date');

$r = $classifier->classify(
    $mount . '/cuomo/2025/12/PROGRAM/CUOMO PGM 2025-12-01 NYNEWOF1ENC006.mxf',
    $mount
);
assert_eq('Program', $r->mediaTypeAbbreviation, 'pgm maps to program');
assert_eq('20251201', $r->fileDate, 'pgm date from iso');
assert_eq('CUOMO/2025/12/Program', $r->proposedDir, 'pgm proposed dir');

echo "\nAll Classifier tests passed.\n";
