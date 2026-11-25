<?php

declare(strict_types=1);

use Haryanvi\Core\Config;
use Haryanvi\Core\Crypto;
use Haryanvi\Tests\Assert;

/**
 * Provider API keys live in the database, so the encryption envelope needs to
 * be authenticated (tamper-evident) and non-deterministic.
 */

Assert::startTest('Crypto');

$key = Crypto::generateKey();
Assert::contains('base64:', $key, 'Generated key is base64-tagged');
Assert::same(32, strlen(base64_decode(substr($key, 7), true) ?: ''), 'Generated key is 32 raw bytes');

// Install the key for the rest of this test file.
$original = Config::get('security.encryption_key');
Config::set(['security' => ['encryption_key' => $key]]);

Assert::true(Crypto::isConfigured(), 'Crypto reports configured with a valid key');

/* ---- Round trip ---- */
$secret = 'sk-test-ABCDEFG1234567890';
$cipher = Crypto::encrypt($secret);
Assert::notContains($secret, $cipher, 'Ciphertext does not contain the plaintext');
Assert::same($secret, Crypto::decrypt($cipher), 'Decrypt returns the original secret');

/* ---- Unicode payloads ---- */
$unicode = 'कुंजी-🔑-key';
Assert::same($unicode, Crypto::decrypt(Crypto::encrypt($unicode)), 'Unicode secret round-trips');

/* ---- Non-deterministic (random IV per message) ---- */
Assert::true(
    Crypto::encrypt($secret) !== Crypto::encrypt($secret),
    'Encrypting twice yields different ciphertext'
);

/* ---- Tamper detection ---- */
$tampered = Crypto::encrypt($secret);
$raw      = base64_decode($tampered, true) ?: '';
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
Assert::throws(
    static fn (): string => Crypto::decrypt(base64_encode($raw)),
    'GCM tag rejects a modified ciphertext'
);

/* ---- Malformed input ---- */
Assert::throws(static fn (): string => Crypto::decrypt('not-base64!!'), 'Malformed envelope is rejected');
Assert::throws(static fn (): string => Crypto::decrypt(base64_encode('short')), 'Truncated envelope is rejected');

/* ---- Wrong key cannot decrypt ---- */
$cipherFromFirstKey = Crypto::encrypt($secret);
Config::set(['security' => ['encryption_key' => Crypto::generateKey()]]);
Assert::throws(
    static fn (): string => Crypto::decrypt($cipherFromFirstKey),
    'A rotated key cannot decrypt old ciphertext'
);

/* ---- Missing key is an explicit failure, never a silent pass-through ---- */
Config::set(['security' => ['encryption_key' => '']]);
Assert::false(Crypto::isConfigured(), 'Crypto reports unconfigured with an empty key');
Assert::throws(static fn (): string => Crypto::encrypt('x'), 'Encrypting without a key throws');

/* ---- Token and UUID generation ---- */
Config::set(['security' => ['encryption_key' => $key]]);
$tokenA = Crypto::randomToken();
$tokenB = Crypto::randomToken();
Assert::true($tokenA !== $tokenB, 'Random tokens are unique');
Assert::greaterThan(32, strlen($tokenA), 'Token is long enough to resist guessing');
Assert::same(0, preg_match('/[^A-Za-z0-9_\-]/', $tokenA), 'Token is URL-safe');

$uuid = Crypto::uuid4();
Assert::same(
    1,
    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid),
    'UUID matches the v4 format'
);
Assert::true(Crypto::uuid4() !== Crypto::uuid4(), 'UUIDs are unique');

// Restore whatever the harness had configured.
Config::set(['security' => ['encryption_key' => is_string($original) ? $original : '']]);
