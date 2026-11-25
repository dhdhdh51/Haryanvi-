<?php

declare(strict_types=1);

namespace Haryanvi\Repositories;

use Haryanvi\Core\Crypto;
use Haryanvi\Core\Database;

/**
 * Bearer token storage.
 *
 * Only SHA-256 digests are persisted. A leaked database therefore yields no
 * usable credentials, and the plaintext token is returned to the client
 * exactly once — at register/login.
 */
final class TokenRepository
{
    /**
     * @return array{token:string,expires_at:string}
     */
    public function issue(int $userId, string $deviceInfo, string $ip, int $ttlDays): array
    {
        $token     = Crypto::randomToken();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400));

        Database::execute(
            'INSERT INTO api_tokens (user_id, token_hash, name, device_info, expires_at, created_ip)
             VALUES (:user_id, :hash, :name, :device, :expires, :ip)',
            [
                'user_id' => $userId,
                'hash'    => self::hash($token),
                'name'    => 'android',
                'device'  => $deviceInfo === '' ? null : mb_substr($deviceInfo, 0, 255),
                'expires' => $expiresAt,
                'ip'      => $ip,
            ]
        );

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Resolve a presented token to its user, enforcing revocation and expiry.
     *
     * @return array{token_id:int,user:array<string,mixed>}|null
     */
    public function resolve(string $token): ?array
    {
        $row = Database::fetchOne(
            'SELECT t.id AS token_id,
                    u.id, u.uuid, u.email, u.display_name, u.status,
                    u.daily_limit, u.monthly_limit
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > UTC_TIMESTAMP())
               AND u.status <> \'deleted\'
             LIMIT 1',
            ['hash' => self::hash($token)]
        );

        if ($row === null) {
            return null;
        }

        $tokenId = (int) $row['token_id'];
        unset($row['token_id']);

        return ['token_id' => $tokenId, 'user' => $row];
    }

    public function touch(int $tokenId): void
    {
        Database::execute(
            'UPDATE api_tokens
             SET last_used_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND (last_used_at IS NULL OR last_used_at < (UTC_TIMESTAMP() - INTERVAL 5 MINUTE))',
            ['id' => $tokenId]
        );
    }

    public function revoke(int $tokenId): void
    {
        Database::execute(
            'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = :id AND revoked_at IS NULL',
            ['id' => $tokenId]
        );
    }

    public function revokeAllForUser(int $userId): int
    {
        return Database::execute(
            'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = :id AND revoked_at IS NULL',
            ['id' => $userId]
        );
    }

    /**
     * Housekeeping: drop tokens that expired or were revoked over 30 days ago.
     */
    public function prune(): int
    {
        return Database::execute(
            'DELETE FROM api_tokens
             WHERE (expires_at IS NOT NULL AND expires_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY))
                OR (revoked_at IS NOT NULL AND revoked_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY))'
        );
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
