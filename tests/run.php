<?php
/**
 * Runs every runtime suite in its own process and aggregates the result.
 *
 *     php tests/run.php
 *
 * Separate processes on purpose: each suite builds its own database, and a
 * fatal error in one should not hide the others' results.
 */

declare(strict_types=1);

$suites = glob(__DIR__ . '/runtime_*.php') ?: [];
sort($suites);

if ($suites === []) {
    fwrite(STDERR, "no suites found in " . __DIR__ . PHP_EOL);
    exit(1);
}

$failed = [];

foreach ($suites as $suite) {
    echo PHP_EOL . str_repeat('=', 62) . PHP_EOL;
    echo basename($suite) . PHP_EOL;
    echo str_repeat('=', 62) . PHP_EOL;

    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($suite), $code);

    if ($code !== 0) {
        $failed[] = basename($suite);
    }
}

echo PHP_EOL . str_repeat('=', 62) . PHP_EOL;

if ($failed === []) {
    printf("OK -- %d suite(s) passed%s", count($suites), PHP_EOL);
    exit(0);
}

printf("FAILED -- %s%s", implode(', ', $failed), PHP_EOL);
exit(1);