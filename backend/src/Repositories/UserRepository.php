<?php

declare(strict_types=1);

namespace Haryanvi\Repositories;

use Haryanvi\Core\Crypto;
use Haryanvi\Core\Database;

/**
 * Persistence for accounts and their settings row.
 */
final class UserRepository
{
    private const SELECT_COLUMNS =
        'id, uuid, email, password_hash, display_name, status, blocked_reason,
         daily_limit, monthly_limit, last_login_at, created_at';

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return Database::fetchOne(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM users
             WHERE email = :email AND status <> \'deleted\'
             LIMIT 1',
            ['email' => strtolower($email)]
        );
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM users
             WHERE id = :id AND status <> \'deleted\'
             LIMIT 1',
            ['id' => $id]
        );
    }

    public function emailExists(string $email): bool
    {
        return Database::fetchValue(
            'SELECT 1 FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower($email)]
        ) !== null;
    }

    /**
     * Create the account and its default settings row atomically.
     *
     * @return array{id:int,uuid:string}
     */
    public function create(string $email, string $passwordHash, string $displayName, string $ip): array
    {
        return Database::transaction(static function () use ($email, $passwordHash, $displayName, $ip): array {
            $uuid = Crypto::uuid4();

            Database::execute(
                'INSERT INTO users (uuid, email, password_hash, display_name, registration_ip)
                 VALUES (:uuid, :email, :hash, :name, :ip)',
                [
                    'uuid'  => $uuid,
                    'email' => strtolower($email),
                    'hash'  => $passwordHash,
                    'name'  => $displayName,
                    'ip'    => $ip,
                ]
            );

            $userId = Database::lastInsertId();

            // Seed settings from the configured defaults.
            Database::execute(
                'INSERT INTO user_settings (user_id, dialect_id, tone_code)
                 VALUES (
                    :user_id,
                    (SELECT id FROM dialects WHERE code = (
                        SELECT COALESCE(setting_value, \'general\') FROM app_settings WHERE setting_key = \'default_dialect_code\'
                    ) LIMIT 1),
                    COALESCE((SELECT setting_value FROM app_settings WHERE setting_key = \'default_tone_code\'), \'natural\')
                 )',
                ['user_id' => $userId]
            );

            return ['id' => $userId, 'uuid' => $uuid];
        });
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        Database::execute(
            'UPDATE users SET password_hash = :hash WHERE id = :id',
            ['hash' => $passwordHash, 'id' => $userId]
        );
    }

    public function touchLogin(int $userId, string $ip): void
    {
        Database::execute(
            'UPDATE users
             SET last_login_at = UTC_TIMESTAMP(), last_seen_at = UTC_TIMESTAMP(), registration_ip = COALESCE(registration_ip, :ip)
             WHERE id = :id',
            ['id' => $userId, 'ip' => $ip]
        );
    }

    public function touchSeen(int $userId): void
    {
        // Minute-level granularity is plenty and avoids a write per request.
        Database::execute(
            'UPDATE users
             SET last_seen_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND (last_seen_at IS NULL OR last_seen_at < (UTC_TIMESTAMP() - INTERVAL 5 MINUTE))',
            ['id' => $userId]
        );
    }

    public function updateProfile(int $userId, string $displayName): void
    {
        Database::execute(
            'UPDATE users SET display_name = :name WHERE id = :id',
            ['name' => $displayName, 'id' => $userId]
        );
    }

    /* ----------------------------------------------------------------
     | Settings
     * -------------------------------------------------------------- */

    /** @return array<string,mixed> */
    public function settings(int $userId): array
    {
        $row = Database::fetchOne(
            'SELECT s.*, d.code AS dialect_code
             FROM user_settings s
             LEFT JOIN dialects d ON d.id = s.dialect_id
             WHERE s.user_id = :id',
            ['id' => $userId]
        );

        if ($row === null) {
            // Self-heal if a settings row is ever missing.
            Database::execute(
                'INSERT INTO user_settings (user_id) VALUES (:id)
                 ON DUPLICATE KEY UPDATE user_id = user_id',
                ['id' => $userId]
            );

            $row = Database::fetchOne(
                'SELECT s.*, d.code AS dialect_code
                 FROM user_settings s
                 LEFT JOIN dialects d ON d.id = s.dialect_id
                 WHERE s.user_id = :id',
                ['id' => $userId]
            ) ?? [];
        }

        return $row;
    }

    /**
     * @param array<string,string|int> $fields already-whitelisted column => value
     */
    public function updateSettings(int $userId, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $allowed = [
            'dialect_id', 'tone_code', 'output_script', 'include_roman',
            'default_source_lang', 'save_history', 'cloud_sync',
            'floating_enabled', 'keyboard_haptics', 'theme',
        ];

        $sets   = [];
        $params = ['user_id' => $userId];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue; // defence in depth — column names never come from input
            }
            $sets[]              = sprintf('%s = :%s', $column, $column);
            $params[$column]     = $value;
        }

        if ($sets === []) {
            return;
        }

        Database::execute(
            'UPDATE user_settings SET ' . implode(', ', $sets) . ' WHERE user_id = :user_id',
            $params
        );
    }

    /* ----------------------------------------------------------------
     | Account lifecycle
     * -------------------------------------------------------------- */

    /**
     * Irreversible account deletion.
     *
     * History, tokens and settings cascade away via foreign keys. The row
     * itself is anonymised rather than hard-deleted so that aggregate usage
     * statistics in the admin dashboard remain consistent.
     */
    public function deleteAccount(int $userId): void
    {
        Database::transaction(static function () use ($userId): void {
            Database::execute('DELETE FROM translation_history WHERE user_id = :id', ['id' => $userId]);
            Database::execute('DELETE FROM api_tokens WHERE user_id = :id', ['id' => $userId]);
            Database::execute('DELETE FROM user_settings WHERE user_id = :id', ['id' => $userId]);

            Database::execute(
                'UPDATE users
                 SET status        = \'deleted\',
                     email         = CONCAT(\'deleted+\', id, \'@invalid.local\'),
                     password_hash = \'\',
                     display_name  = \'Deleted user\',
                     registration_ip = NULL,
                     deleted_at    = UTC_TIMESTAMP()
                 WHERE id = :id',
                ['id' => $userId]
            );
        });
    }

    /* ----------------------------------------------------------------
     | Admin queries
     * -------------------------------------------------------------- */

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function paginateForAdmin(string $search, string $status, int $page, int $perPage): array
    {
        $where  = ['u.status <> \'deleted\''];
        $params = [];

        if ($search !== '') {
            // One bind per placeholder: native prepares do not reuse names.
            $where[]               = '(u.email LIKE :search_email OR u.display_name LIKE :search_name)';
            $params['search_email'] = '%' . $search . '%';
            $params['search_name']  = '%' . $search . '%';
        }

        if (in_array($status, ['active', 'blocked'], true)) {
            $where[]          = 'u.status = :status';
            $params['status'] = $status;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM users u ' . $whereSql,
            $params
        );

        $offset = max(0, ($page - 1) * $perPage);

        $rows = Database::fetchAll(
            'SELECT u.id, u.uuid, u.email, u.display_name, u.status, u.daily_limit, u.monthly_limit,
                    u.last_login_at, u.created_at,
                    COALESCE(today.translations, 0) AS translations_today,
                    COALESCE(total.translations, 0) AS translations_total
             FROM users u
             LEFT JOIN usage_daily today
                    ON today.user_id = u.id AND today.usage_date = UTC_DATE()
             LEFT JOIN (
                    SELECT user_id, SUM(translations) AS translations
                    FROM usage_daily GROUP BY user_id
             ) total ON total.user_id = u.id
             ' . $whereSql . '
             ORDER BY u.created_at DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public function setStatus(int $userId, string $status, ?string $reason): void
    {
        Database::transaction(static function () use ($userId, $status, $reason): void {
            Database::execute(
                'UPDATE users SET status = :status, blocked_reason = :reason WHERE id = :id',
                ['status' => $status, 'reason' => $reason, 'id' => $userId]
            );

            // Blocking must take effect immediately, not at token expiry.
            if ($status === 'blocked') {
                Database::execute(
                    'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP()
                     WHERE user_id = :id AND revoked_at IS NULL',
                    ['id' => $userId]
                );
            }
        });
    }

    public function setLimits(int $userId, ?int $daily, ?int $monthly): void
    {
        Database::execute(
            'UPDATE users SET daily_limit = :daily, monthly_limit = :monthly WHERE id = :id',
            ['daily' => $daily, 'monthly' => $monthly, 'id' => $userId]
        );
    }

    public function countAll(): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM users WHERE status <> \'deleted\'');
    }

    public function countActiveSince(int $days): int
    {
        // MySQL will not accept a placeholder inside an INTERVAL expression,
        // so the window is clamped and interpolated as an integer literal.
        return (int) Database::fetchValue(
            sprintf(
                'SELECT COUNT(*) FROM users
                 WHERE status = \'active\' AND last_seen_at >= (UTC_TIMESTAMP() - INTERVAL %d DAY)',
                max(1, min(3650, $days))
            )
        );
    }

    public function countBlocked(): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM users WHERE status = \'blocked\'');
    }

    public function countRegisteredSince(int $days): int
    {
        return (int) Database::fetchValue(
            sprintf(
                'SELECT COUNT(*) FROM users
                 WHERE status <> \'deleted\' AND created_at >= (UTC_TIMESTAMP() - INTERVAL %d DAY)',
                max(1, min(3650, $days))
            )
        );
    }
}
