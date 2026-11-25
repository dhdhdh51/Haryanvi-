<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * Minimal rotating file logger (one file per day).
 *
 * Privacy rule enforced by convention throughout the codebase: user text is
 * never written to the log. Call sites log sizes, hashes and identifiers only.
 */
final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    private static string $path      = '';
    private static int    $threshold = 30;
    private static bool   $ready     = false;

    public static function init(string $path, string $level = 'warning'): void
    {
        self::$path      = rtrim($path, '/');
        self::$threshold = self::LEVELS[strtolower($level)] ?? 30;

        if (!is_dir(self::$path)) {
            @mkdir(self::$path, 0750, true);
        }

        self::$ready = is_dir(self::$path) && is_writable(self::$path);
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function exception(\Throwable $e, array $context = []): void
    {
        self::write('error', $e->getMessage(), $context + [
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine(),
            'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ]);
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 0) < self::$threshold) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . self::encodeContext($context)
        );

        if (self::$ready) {
            $file = self::$path . '/app-' . gmdate('Y-m-d') . '.log';
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
            @chmod($file, 0640);

            return;
        }

        // Storage not writable — fall back to the PHP error log so nothing is lost.
        error_log('haryanvi: ' . rtrim($line));
    }

    /** @param array<string,mixed> $context */
    private static function encodeContext(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '{"context":"unencodable"}' : $json;
    }
}
