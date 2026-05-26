#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = glob($root . '/tests/*Test.php') ?: [];
$failed = 0;

foreach ($tests as $test) {
    echo '── ' . basename($test) . ' ──' . PHP_EOL;
    passthru(PHP_BINARY . ' ' . escapeshellarg($test), $code);
    if ($code !== 0) {
        $failed++;
    }
    echo PHP_EOL;
}

exit($failed > 0 ? 1 : 0);
