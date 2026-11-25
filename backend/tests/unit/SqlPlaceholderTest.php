<?php

declare(strict_types=1);

use Haryanvi\Tests\Assert;

/**
 * Guards against a mistake this codebase has already made twice.
 *
 * PDO with ATTR_EMULATE_PREPARES disabled sends statements to MySQL as real
 * prepared statements, and each named placeholder is bound exactly once. A
 * query like `WHERE a = :term OR b = :term` therefore fails at runtime with
 * SQLSTATE[HY093] — which only shows up when that code path is exercised.
 *
 * This test scans every SQL string in the source tree and fails if any single
 * statement repeats a placeholder name, so the problem is caught at build
 * time rather than in production.
 */

Assert::startTest('SQL placeholder hygiene');

$sourceRoot = dirname(__DIR__, 2) . '/src';

/** @return list<string> */
$phpFiles = static function (string $directory): array {
    $files    = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
};

$files = $phpFiles($sourceRoot);
Assert::greaterThan(20, count($files), 'Source tree located for scanning');

$offenders       = [];
$statementsSeen  = 0;

foreach ($files as $file) {
    $contents = (string) file_get_contents($file);

    // Single-quoted strings that look like SQL statements. The project writes
    // every query as a single-quoted PHP string, so this covers all of them.
    if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/s", $contents, $matches) === false) {
        continue;
    }

    foreach ($matches[1] as $candidate) {
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $candidate) !== 1) {
            continue;
        }
        if (!str_contains($candidate, ':')) {
            continue;
        }

        $statementsSeen++;

        // Collect placeholder names, ignoring ::casts and time literals.
        if (preg_match_all('/(?<![:\w])\:([a-zA-Z_][a-zA-Z0-9_]*)/', $candidate, $found) === false) {
            continue;
        }

        $names  = $found[1];
        $counts = array_count_values($names);

        foreach ($counts as $name => $count) {
            if ($count > 1) {
                $offenders[] = sprintf(
                    '%s: ":%s" appears %d times in one statement',
                    basename($file),
                    $name,
                    $count
                );
            }
        }
    }
}

Assert::greaterThan(30, $statementsSeen, 'Scanner found the SQL statements to check');

Assert::same(
    [],
    $offenders,
    $offenders === []
        ? 'No SQL statement reuses a named placeholder'
        : 'Reused placeholders found — ' . implode('; ', array_slice($offenders, 0, 5))
);
