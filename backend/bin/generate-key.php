<?php
/**
 * Generates the AES-256-GCM master key for config/config.php.
 *
 *   php bin/generate-key.php
 *
 * The key encrypts AI provider API keys at rest. Changing it later makes
 * previously stored provider keys unreadable — re-enter them in the admin
 * dashboard if you rotate it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require __DIR__ . '/../src/Core/Crypto.php';
require __DIR__ . '/../src/Core/Config.php';

$key = \Haryanvi\Core\Crypto::generateKey();

echo <<<TXT

  Encryption key generated.

  Paste this into backend/config/config.php:

      'security' => [
          'encryption_key' => '{$key}',
          ...
      ],

  Keep it secret. Do not commit it. Do not reuse it across installations.


TXT;
