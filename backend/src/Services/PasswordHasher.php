<?php

declare(strict_types=1);

namespace Haryanvi\Services;

/**
 * Password hashing policy in one place.
 *
 * Argon2id is preferred where the PHP build provides it; otherwise bcrypt at
 * cost 12. Hashes are self-describing, so a host that later gains Argon2
 * support will transparently upgrade existing users at their next login via
 * needsRehash().
 */
final class PasswordHasher
{
    private const BCRYPT_COST = 12;

    public static function hash(string $plaintext): string
    {
        $algorithm = self::algorithm();

        $hash = $algorithm === PASSWORD_BCRYPT
            ? password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])
            : password_hash($plaintext, $algorithm, [
                'memory_cost' => 65536, // 64 MiB
                'time_cost'   => 4,
                'threads'     => 1,
            ]);

        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Password hashing failed on this server.');
        }

        return $hash;
    }

    public static function verify(string $plaintext, string $hash): bool
    {
        if ($hash === '') {
            // Deleted accounts have an empty hash; still burn the time so the
            // response is not measurably faster.
            self::dummyVerify();

            return false;
        }

        return password_verify($plaintext, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algorithm = self::algorithm();

        return $algorithm === PASSWORD_BCRYPT
            ? password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])
            : password_needs_rehash($hash, $algorithm);
    }

    /**
     * Constant-ish work for unknown accounts, so login timing does not reveal
     * whether an email address is registered.
     */
    public static function dummyVerify(): void
    {
        password_verify(
            'timing-equalisation',
            '$2y$12$u3pWMv7MpA62katTwPmxyeoIJ8nyNOhPaplCwbO80cguwgYdUHMOa'
        );
    }

    private static function algorithm(): string
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return PASSWORD_ARGON2ID;
        }

        return PASSWORD_BCRYPT;
    }

    public static function algorithmName(): string
    {
        return self::algorithm() === PASSWORD_BCRYPT ? 'bcrypt' : 'argon2id';
    }
}
