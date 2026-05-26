<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use MediaManager\Services\ScanIgnore;

/** @param mixed $expected */
function assert_true(bool $value, string $label): void
{
    if (!$value) {
        throw new RuntimeException("FAIL {$label}");
    }
    echo "OK {$label}\n";
}

/** @param mixed $expected */
function assert_false(bool $value, string $label): void
{
    assert_true(!$value, $label);
}

$mount = '/mnt-smb/SNSEVO-NYL';
$ignore = new ScanIgnore([
    ['prefix' => $mount . '/SPECIAL PROGRAMMING', 'source_mount' => $mount],
]);

assert_true(
    $ignore->shouldIgnore($mount . '/SPECIAL PROGRAMMING/RAW INGEST/foo.mxf', $mount),
    'ignores file under SPECIAL PROGRAMMING'
);
assert_true(
    $ignore->shouldIgnoreDirectory($mount . '/SPECIAL PROGRAMMING', $mount),
    'ignores SPECIAL PROGRAMMING directory'
);
assert_false(
    $ignore->shouldIgnore($mount . '/cuomo/2025/12/CLEAN/file.ts', $mount),
    'does not ignore cuomo tree'
);
assert_true(
    $ignore->shouldIgnore($mount . '/.Trash/files/deleted.ts', $mount),
    'built-in trash rule still applies'
);

echo "\nAll ScanIgnore tests passed.\n";
