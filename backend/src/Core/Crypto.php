<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * AES-256-GCM envelope encryption for data at rest.
 *
 * Used for AI provider API keys so the database never holds a usable secret.
 * The master key lives only in config/config.php on the server filesystem.
 *
 * Envelope format (base64 encoded):
 *     v1 | 12-byte IV | 16-byte GCM tag | ciphertext
 */
final class Crypto
{
    private const CIPHER      = 'aes-256-gcm';
    private const VERSION     = "\x01";
    private const IV_BYTES    = 12;
    private const TAG_BYTES   = 16;
    private const KEY_BYTES   = 32;

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(self::KEY_BYTES));
    }

    public static function isConfigured(): bool
    {
        try {
            self::key();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv  = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode(self::VERSION . $iv . $tag . $ciphertext);
    }

    /**
     * @throws \RuntimeException when the envelope is malformed or the key is wrong
     */
    public static function decrypt(string $envelope): string
    {
        $raw = base64_decode($envelope, true);

        if ($raw === false || strlen($raw) < 1 + self::IV_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Malformed ciphertext envelope.');
        }

        if ($raw[0] !== self::VERSION) {
            throw new \RuntimeException('Unsupported ciphertext version.');
        }

        $iv         = substr($raw, 1, self::IV_BYTES);
        $tag        = substr($raw, 1 + self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, 1 + self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // Wrong key, or the stored value was tampered with.
            throw new \RuntimeException(
                'Could not decrypt the stored secret. The encryption key may have changed.'
            );
        }

        return $plaintext;
    }

    /**
     * Constant-time comparison helper for tokens and signatures.
     */
    public static function hashEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /**
     * Opaque bearer token: 32 random bytes, URL-safe base64, no padding.
     */
    public static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function key(): string
    {
        $configured = Config::getString('security.encryption_key');

        if ($configured === '') {
            throw new \RuntimeException(
                'security.encryption_key is empty. Run "php bin/generate-key.php" '
                . 'and paste the value into config/config.php.'
            );
        }

        $key = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException(
                'security.encryption_key must decode to exactly 32 bytes. '
                . 'Regenerate it with "php bin/generate-key.php".'
            );
        }

        return $key;
    }
}
