<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * Immutable configuration holder with dot-path access.
 *
 *   Config::get('db.host');
 *   Config::get('security.cors_origins', []);
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    public static function load(string $file): void
    {
        /** @var mixed $data */
        $data = require $file;

        if (!is_array($data)) {
            throw new \RuntimeException('Configuration file must return an array: ' . $file);
        }

        self::$items  = $data;
        self::$loaded = true;
    }

    /**
     * Mainly for tests and CLI tools that build config in memory.
     *
     * @param array<string,mixed> $items
     */
    public static function set(array $items): void
    {
        self::$items  = $items;
        self::$loaded = true;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $cursor   = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public static function getString(string $path, string $default = ''): string
    {
        $value = self::get($path, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function getInt(string $path, int $default = 0): int
    {
        $value = self::get($path, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function getBool(string $path, bool $default = false): bool
    {
        $value = self::get($path, $default);

        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    /** @return array<int|string,mixed> */
    public static function getArray(string $path, array $default = []): array
    {
        $value = self::get($path, $default);

        return is_array($value) ? $value : $default;
    }

    public static function isDebug(): bool
    {
        return self::getBool('app.debug', false);
    }
}
