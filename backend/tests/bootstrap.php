<?php
/**
 * Test bootstrap.
 *
 * Registers the autoloader without requiring config/config.php, so the unit
 * suite runs on a bare checkout. Integration tests supply their own
 * configuration through environment variables.
 */

declare(strict_types=1);

define('HY_TEST_ROOT', __DIR__);
define('HY_APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    foreach ([
        'Haryanvi\\Tests\\' => HY_TEST_ROOT . '/',
        'Haryanvi\\'        => HY_APP_ROOT . '/src/',
    ] as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require $path;

            return;
        }
    }
});

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

// Unit tests must not depend on a config file existing.
if (!\Haryanvi\Core\Config::isLoaded()) {
    \Haryanvi\Core\Config::set([
        'app'      => ['env' => 'testing', 'debug' => true, 'timezone' => 'UTC'],
        'security' => [
            'encryption_key' => \Haryanvi\Core\Crypto::generateKey(),
            'force_https'    => false,
            'max_body_bytes' => 65536,
        ],
        'logging'  => ['path' => sys_get_temp_dir() . '/haryanvi-tests', 'level' => 'error'],
    ]);
}

\Haryanvi\Core\Logger::init(sys_get_temp_dir() . '/haryanvi-tests', 'error');
