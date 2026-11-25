<?php

declare(strict_types=1);

namespace Haryanvi\Admin;

use Haryanvi\Core\Database;

/**
 * Administrator accounts and the audit trail.
 */
final class AdminRepository
{
    private const LOCK_THRESHOLD = 5;
    private const LOCK_MINUTES   = 15;

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        // Each placeholder is bound once: native prepared statements do not
        // allow reusing a parameter name.
        return Database::fetchOne(
            'SELECT id, username, email, password_hash, full_name, role, is_active,
                    must_change_password, failed_attempts, locked_until
             FROM admin_users
             WHERE username = :login_username OR email = :login_email
             LIMIT 1',
            ['login_username' => $username, 'login_email' => $username]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT id, username, email, password_hash, full_name, role, is_active,
                    must_change_password, last_login_at
             FROM admin_users
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Database::fetchAll(
            'SELECT id, username, email, full_name, role, is_active,
                    must_change_password, last_login_at, last_login_ip, created_at
             FROM admin_users
             ORDER BY FIELD(role, \'super_admin\', \'admin\', \'moderator\'), username'
        );
    }

    public function isLocked(array $admin): bool
    {
        $lockedUntil = $admin['locked_until'] ?? null;

        if (!is_string($lockedUntil) || $lockedUntil === '') {
            return false;
        }

        return strtotime($lockedUntil . ' UTC') > time();
    }

    public function lockRemainingSeconds(array $admin): int
    {
        $lockedUntil = $admin['locked_until'] ?? null;

        if (!is_string($lockedUntil) || $lockedUntil === '') {
            return 0;
        }

        return max(0, (strtotime($lockedUntil . ' UTC') ?: 0) - time());
    }

    /**
     * Count a failed attempt and lock the account once the threshold is hit.
     */
    public function registerFailedAttempt(int $adminId): void
    {
        Database::execute(
            sprintf(
                'UPDATE admin_users
                 SET failed_attempts = failed_attempts + 1,
                     locked_until = CASE
                        WHEN failed_attempts + 1 >= %d
                        THEN (UTC_TIMESTAMP() + INTERVAL %d MINUTE)
                        ELSE locked_until
                     END
                 WHERE id = :id',
                self::LOCK_THRESHOLD,
                self::LOCK_MINUTES
            ),
            ['id' => $adminId]
        );
    }

    public function registerSuccessfulLogin(int $adminId, string $ip): void
    {
        Database::execute(
            'UPDATE admin_users
             SET failed_attempts = 0, locked_until = NULL,
                 last_login_at = UTC_TIMESTAMP(), last_login_ip = :ip
             WHERE id = :id',
            ['id' => $adminId, 'ip' => $ip]
        );
    }

    public function updatePassword(int $adminId, string $hash): void
    {
        Database::execute(
            'UPDATE admin_users
             SET password_hash = :hash, must_change_password = 0,
                 failed_attempts = 0, locked_until = NULL
             WHERE id = :id',
            ['hash' => $hash, 'id' => $adminId]
        );
    }

    public function create(string $username, string $email, string $hash, string $fullName, string $role): int
    {
        Database::execute(
            'INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active)
             VALUES (:username, :email, :hash, :name, :role, 1)',
            [
                'username' => $username,
                'email'    => $email,
                'hash'     => $hash,
                'name'     => $fullName,
                'role'     => $role,
            ]
        );

        return Database::lastInsertId();
    }

    public function setActive(int $adminId, bool $active): void
    {
        Database::execute(
            'UPDATE admin_users SET is_active = :active WHERE id = :id',
            ['active' => $active ? 1 : 0, 'id' => $adminId]
        );
    }

    public function usernameExists(string $username, string $email): bool
    {
        return Database::fetchValue(
            'SELECT 1 FROM admin_users WHERE username = :username OR email = :email LIMIT 1',
            ['username' => $username, 'email' => $email]
        ) !== null;
    }

    public function countSuperAdmins(): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM admin_users WHERE role = \'super_admin\' AND is_active = 1'
        );
    }

    /* ----------------------------------------------------------------
     | Audit trail
     * -------------------------------------------------------------- */

    /** @param array<string,mixed> $details */
    public function log(
        ?int $adminId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $details = [],
        string $ip = '',
        string $userAgent = '',
    ): void {
        Database::execute(
            'INSERT INTO admin_activity_logs
                (admin_id, action, entity_type, entity_id, details, ip_address, user_agent)
             VALUES
                (:admin_id, :action, :entity_type, :entity_id, :details, :ip, :user_agent)',
            [
                'admin_id'    => $adminId,
                'action'      => mb_substr($action, 0, 100),
                'entity_type' => $entityType === null ? null : mb_substr($entityType, 0, 60),
                'entity_id'   => $entityId === null ? null : mb_substr($entityId, 0, 60),
                'details'     => $details === []
                    ? null
                    : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'ip'          => $ip === '' ? null : mb_substr($ip, 0, 45),
                'user_agent'  => $userAgent === '' ? null : mb_substr($userAgent, 0, 255),
            ]
        );
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function auditTrail(int $page, int $perPage): array
    {
        $total   = (int) Database::fetchValue('SELECT COUNT(*) FROM admin_activity_logs');
        $perPage = max(1, min(200, $perPage));
        $offset  = max(0, ($page - 1) * $perPage);

        $rows = Database::fetchAll(
            'SELECT l.*, COALESCE(a.username, \'(deleted)\') AS admin_username
             FROM admin_activity_logs l
             LEFT JOIN admin_users a ON a.id = l.admin_id
             ORDER BY l.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Retention housekeeping for the audit log.
     */
    public function pruneAuditTrail(int $days = 365): int
    {
        return Database::execute(
            sprintf(
                'DELETE FROM admin_activity_logs WHERE created_at < (UTC_DATE() - INTERVAL %d DAY)',
                max(30, min(3650, $days))
            )
        );
    }
}
