<?php
/**
 * Application bootstrap.
 *
 * Registers the PSR-4 autoloader, loads configuration and prepares the
 * runtime. Included by both the API front controller (index.php) and the
 * admin dashboard (admin/index.php), and by the CLI scripts in bin/.
 *
 * Deliberately free of Composer so the project drops onto any shared host.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'UNSUPPORTED_PHP_VERSION',
            'message' => 'PHP 8.2 or newer is required. Detected ' . PHP_VERSION . '.',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

define('HY_SRC_PATH',  __DIR__);
define('HY_BASE_PATH', dirname(__DIR__));

/* ---------------------------------------------------------------------
 | PSR-4 autoloader:  Haryanvi\Foo\Bar  ->  src/Foo/Bar.php
 * ------------------------------------------------------------------- */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Haryanvi\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path     = HY_SRC_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

/* ---------------------------------------------------------------------
 | Configuration
 * ------------------------------------------------------------------- */
$configFile = HY_BASE_PATH . '/config/config.php';

if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'CONFIG_MISSING',
            'message' => 'config/config.php not found. Copy config/config.example.php to '
                       . 'config/config.php and fill in your database credentials.',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

\Haryanvi\Core\Config::load($configFile);

/* ---------------------------------------------------------------------
 | Runtime hardening
 * ------------------------------------------------------------------- */
date_default_timezone_set(\Haryanvi\Core\Config::get('app.timezone', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

$debug = (bool) \Haryanvi\Core\Config::get('app.debug', false);

// Never print raw PHP errors to a JSON client; everything is logged instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);

\Haryanvi\Core\Logger::init(
    (string) \Haryanvi\Core\Config::get('logging.path', HY_BASE_PATH . '/storage/logs'),
    (string) \Haryanvi\Core\Config::get('logging.level', 'warning')
);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
