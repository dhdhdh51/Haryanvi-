<?php
/**
 * Test runner.
 *
 *   php tests/run.php                  # unit tests only
 *   php tests/run.php --all            # unit + integration
 *   php tests/run.php --filter=Crypto  # run matching files only
 *
 * Integration tests need a running instance and these variables:
 *
 *   HARYANVI_TEST_URL   base URL, e.g. http://127.0.0.1:8080
 *   HARYANVI_TEST_EMAIL optional seed email (default: a unique throwaway)
 *
 * Exit code is non-zero when anything fails, so CI can gate on it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tests may only be run from the command line.\n");
}

require __DIR__ . '/bootstrap.php';

use Haryanvi\Tests\Assert;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--filter=')) {
        $options['filter'] = substr($argument, 9);
    } elseif ($argument === '--all') {
        $options['all'] = true;
    }
}

$suites = ['unit'];

if (($options['all'] ?? false) === true) {
    if (getenv('HARYANVI_TEST_URL') === false) {
        fwrite(STDERR, "--all was given but HARYANVI_TEST_URL is not set; skipping integration tests.\n\n");
    } else {
        $suites[] = 'integration';
    }
}

$started = microtime(true);
$files   = [];

foreach ($suites as $suite) {
    $directory = __DIR__ . '/' . $suite;

    if (!is_dir($directory)) {
        continue;
    }

    $found = glob($directory . '/*Test.php') ?: [];
    sort($found);

    foreach ($found as $file) {
        if (isset($options['filter']) && !str_contains(basename($file), $options['filter'])) {
            continue;
        }
        $files[] = $file;
    }
}

if ($files === []) {
    fwrite(STDERR, "No test files matched.\n");
    exit(1);
}

echo "\n\033[1mHaryanvi AI — backend test suite\033[0m\n";
echo str_repeat('=', 62) . "\n";

foreach ($files as $file) {
    echo "\n\033[1m" . basename($file, '.php') . "\033[0m\n";

    try {
        require $file;
    } catch (\Throwable $e) {
        Assert::$failures[] = basename($file) . ' :: fatal — ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        echo "  \033[31m✗ aborted: " . $e->getMessage() . "\033[0m\n";
    }
}

$elapsed = round((microtime(true) - $started) * 1000);
$failed  = count(Assert::$failures);

echo "\n" . str_repeat('=', 62) . "\n";

if ($failed === 0) {
    printf("\033[32mPASS\033[0m  %d assertions in %dms\n\n", Assert::$passed, $elapsed);
    exit(0);
}

printf("\033[31mFAIL\033[0m  %d passed, %d failed in %dms\n\n", Assert::$passed, $failed, $elapsed);

foreach (Assert::$failures as $index => $failure) {
    printf("  %d) %s\n", $index + 1, $failure);
}

echo "\n";
exit(1);
