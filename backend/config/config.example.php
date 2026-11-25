<?php
/**
 * Haryanvi AI — server configuration template.
 *
 * SETUP
 *   1. cp config.example.php config.php
 *   2. Fill in the database credentials your host gave you.
 *   3. Generate the encryption key:  php bin/generate-key.php
 *   4. chmod 600 config.php
 *
 * config.php is listed in .gitignore and must NEVER be committed.
 * It is also blocked from the web by config/.htaccess.
 */

return [

    'app' => [
        // 'production' | 'development'
        'env'      => 'production',

        // When true, API errors include stack traces. Keep false in production.
        'debug'    => false,

        // Public base URL of the installation, no trailing slash.
        'url'      => 'https://example.com',

        'timezone' => 'Asia/Kolkata',
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'haryanvi_ai',
        'user'    => 'haryanvi_user',
        'pass'    => '',
        'charset' => 'utf8mb4',

        // Most shared hosts use a TCP socket; set this only if yours needs it.
        'socket'  => null,
    ],

    'security' => [
        /*
         * Master key for AES-256-GCM encryption of AI provider API keys.
         * Generate with:  php bin/generate-key.php
         * Format: "base64:...." (32 raw bytes)
         *
         * Rotating this value makes existing stored provider keys
         * undecryptable — re-enter them in the admin panel afterwards.
         */
        'encryption_key' => '',

        // Reject plain-HTTP requests. Leave true whenever SSL is installed.
        'force_https'    => true,

        // PHP session cookie name for the admin dashboard.
        'session_name'   => 'hy_admin_sess',

        // Bearer tokens issued to the Android client expire after N days.
        'token_ttl_days' => 90,

        /*
         * Browser origins allowed to call the API cross-origin.
         * The Android app does not need this (native HTTP is not subject
         * to CORS). Keep it empty unless you ship a web client.
         *   e.g. ['https://app.example.com']
         */
        'cors_origins'   => [],

        // Hard ceiling on request body size, in bytes.
        'max_body_bytes' => 65536,
    ],

    'logging' => [
        // Must be writable by PHP. Blocked from the web by storage/.htaccess.
        'path'  => __DIR__ . '/../storage/logs',

        // 'debug' | 'info' | 'warning' | 'error'
        'level' => 'warning',
    ],
];
