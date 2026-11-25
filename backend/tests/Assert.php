<?php

declare(strict_types=1);

namespace Haryanvi\Tests;

/**
 * Tiny assertion library.
 *
 * The project has no Composer dependency on purpose (shared hosting), so the
 * test suite brings its own runner rather than pulling in PHPUnit.
 */
final class Assert
{
    public static int $passed = 0;

    /** @var list<string> */
    public static array $failures = [];

    private static string $currentTest = '';

    public static function startTest(string $name): void
    {
        self::$currentTest = $name;
    }

    public static function true(bool $condition, string $message): void
    {
        self::record($condition, $message, 'expected true, got false');
    }

    public static function false(bool $condition, string $message): void
    {
        self::record(!$condition, $message, 'expected false, got true');
    }

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        self::record(
            $expected === $actual,
            $message,
            sprintf('expected %s, got %s', self::render($expected), self::render($actual))
        );
    }

    public static function equals(mixed $expected, mixed $actual, string $message): void
    {
        self::record(
            $expected == $actual,
            $message,
            sprintf('expected %s, got %s', self::render($expected), self::render($actual))
        );
    }

    public static function contains(string $needle, string $haystack, string $message): void
    {
        self::record(
            str_contains($haystack, $needle),
            $message,
            sprintf('%s not found in %s', self::render($needle), self::render(mb_substr($haystack, 0, 300)))
        );
    }

    public static function notContains(string $needle, string $haystack, string $message): void
    {
        self::record(
            !str_contains($haystack, $needle),
            $message,
            sprintf('%s unexpectedly present', self::render($needle))
        );
    }

    public static function greaterThan(float|int $floor, float|int $actual, string $message): void
    {
        self::record(
            $actual > $floor,
            $message,
            sprintf('expected > %s, got %s', self::render($floor), self::render($actual))
        );
    }

    public static function notNull(mixed $value, string $message): void
    {
        self::record($value !== null, $message, 'expected a value, got null');
    }

    public static function isNull(mixed $value, string $message): void
    {
        self::record($value === null, $message, 'expected null, got ' . self::render($value));
    }

    /**
     * Assert that a callable throws, optionally checking the error code of an
     * ApiException.
     */
    public static function throws(callable $work, string $message, ?string $expectedCode = null): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            if ($expectedCode === null) {
                self::record(true, $message, '');

                return;
            }

            $actualCode = $e instanceof \Haryanvi\Core\ApiException ? $e->errorCode() : $e::class;

            self::record(
                $actualCode === $expectedCode,
                $message,
                sprintf('expected error code %s, got %s (%s)', $expectedCode, $actualCode, $e->getMessage())
            );

            return;
        }

        self::record(false, $message, 'expected an exception, none was thrown');
    }

    private static function record(bool $ok, string $message, string $detail): void
    {
        if ($ok) {
            self::$passed++;
            fwrite(STDOUT, "  \033[32m✓\033[0m " . $message . "\n");

            return;
        }

        self::$failures[] = self::$currentTest . ' :: ' . $message . ' — ' . $detail;
        fwrite(STDOUT, "  \033[31m✗\033[0m " . $message . "  (" . $detail . ")\n");
    }

    private static function render(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: gettype($value);
    }
}
