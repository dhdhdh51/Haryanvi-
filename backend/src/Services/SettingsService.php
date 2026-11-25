<?php

declare(strict_types=1);

namespace Haryanvi\Services;

use Haryanvi\Core\Database;

/**
 * Typed access to the `app_settings` table.
 *
 * Values are loaded once per request and cached in memory, so the dozens of
 * lookups a single translation performs cost one query.
 */
final class SettingsService
{
    /** @var array<string,array{value:?string,type:string,is_public:bool}>|null */
    private static ?array $cache = null;

    /* ----------------------------------------------------------------
     | Reads
     * -------------------------------------------------------------- */

    public static function getString(string $key, string $default = ''): string
    {
        $raw = self::raw($key);

        return $raw === null ? $default : $raw;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $raw = self::raw($key);

        return $raw !== null && is_numeric($raw) ? (int) $raw : $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $raw = self::raw($key);

        if ($raw === null) {
            return $default;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,mixed> */
    public static function getJson(string $key): array
    {
        $raw = self::raw($key);

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Settings flagged `is_public` — the payload for GET /api/v1/settings.
     *
     * @return array<string,mixed>
     */
    public static function publicSettings(): array
    {
        $out = [];

        foreach (self::all() as $key => $meta) {
            if (!$meta['is_public']) {
                continue;
            }

            $out[$key] = match ($meta['type']) {
                'int'  => (int) ($meta['value'] ?? 0),
                'bool' => in_array(strtolower((string) ($meta['value'] ?? '')), ['1', 'true', 'yes', 'on'], true),
                'json' => json_decode((string) ($meta['value'] ?? '[]'), true) ?: [],
                default => (string) ($meta['value'] ?? ''),
            };
        }

        return $out;
    }

    /**
     * Full rows for the admin settings screen, grouped for display.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function grouped(): array
    {
        $rows = Database::fetchAll(
            'SELECT setting_key, setting_value, value_type, group_name, label, description, is_public
             FROM app_settings
             ORDER BY group_name ASC, label ASC'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['group_name']][] = $row;
        }

        return $grouped;
    }

    /* ----------------------------------------------------------------
     | Writes
     * -------------------------------------------------------------- */

    /**
     * Update an existing setting. Unknown keys are ignored so a tampered
     * admin form cannot inject new configuration rows.
     */
    public static function set(string $key, string $value): bool
    {
        $affected = Database::execute(
            'UPDATE app_settings SET setting_value = :value WHERE setting_key = :key',
            ['value' => $value, 'key' => $key]
        );

        self::$cache = null;

        return $affected > 0;
    }

    /**
     * @param array<string,string> $values
     * @return list<string> keys that were actually updated
     */
    public static function setMany(array $values): array
    {
        $updated = [];

        Database::transaction(static function () use ($values, &$updated): void {
            foreach ($values as $key => $value) {
                $affected = Database::execute(
                    'UPDATE app_settings SET setting_value = :value WHERE setting_key = :key',
                    ['value' => $value, 'key' => $key]
                );
                if ($affected > 0) {
                    $updated[] = $key;
                }
            }
        });

        self::$cache = null;

        return $updated;
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /* ----------------------------------------------------------------
     | Convenience accessors for frequently used limits
     * -------------------------------------------------------------- */

    public static function maxInputChars(): int
    {
        return max(1, self::getInt('max_input_chars', 1200));
    }

    public static function freeDailyLimit(): int
    {
        return self::getInt('free_daily_limit', 50);
    }

    public static function freeMonthlyLimit(): int
    {
        return self::getInt('free_monthly_limit', 800);
    }

    public static function isMaintenanceMode(): bool
    {
        return self::getBool('maintenance_mode', false);
    }

    public static function maintenanceMessage(): string
    {
        return self::getString(
            'maintenance_message',
            'The service is temporarily unavailable for maintenance.'
        );
    }

    public static function registrationOpen(): bool
    {
        return self::getBool('allow_registration', true);
    }

    public static function tokenTtlDays(): int
    {
        return max(1, self::getInt('token_ttl_days', 90));
    }

    /** @return list<string> */
    public static function corsOrigins(): array
    {
        $raw = self::getString('cors_allowed_origins');

        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /* ----------------------------------------------------------------
     | Internals
     * -------------------------------------------------------------- */

    private static function raw(string $key): ?string
    {
        $all = self::all();

        if (!array_key_exists($key, $all)) {
            return null;
        }

        return $all[$key]['value'];
    }

    /** @return array<string,array{value:?string,type:string,is_public:bool}> */
    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = Database::fetchAll(
            'SELECT setting_key, setting_value, value_type, is_public FROM app_settings'
        );

        $cache = [];
        foreach ($rows as $row) {
            $cache[(string) $row['setting_key']] = [
                'value'     => $row['setting_value'] === null ? null : (string) $row['setting_value'],
                'type'      => (string) $row['value_type'],
                'is_public' => (bool) $row['is_public'],
            ];
        }

        return self::$cache = $cache;
    }
}
