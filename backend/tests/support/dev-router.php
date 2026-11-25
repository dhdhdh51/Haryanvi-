<?php
/**
 * Router for PHP's built-in web server, standing in for Apache + .htaccess
 * during local development and integration testing.
 *
 *   php -S 127.0.0.1:8080 -t backend backend/tests/support/dev-router.php
 *
 * Then:
 *   API    http://127.0.0.1:8080/api/v1/health
 *   Admin  http://127.0.0.1:8080/admin/
 *
 * Mirrors the production rules: /api/* goes to the front controller, the
 * sensitive directories are blocked, and everything else is served as a file.
 *
 * Not used in production — Apache handles routing there via .htaccess.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2); // .../backend
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

/* ---- Mirror the .htaccess denials ---------------------------------- */
foreach (['/config', '/src', '/database', '/storage', '/bin', '/tests'] as $blocked) {
    if ($path === $blocked || str_starts_with($path, $blocked . '/')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden\n";

        return true;
    }
}

// Admin view templates are includes, never pages.
if (str_starts_with($path, '/admin/views/')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n";

    return true;
}

/* ---- API front controller ------------------------------------------ */
if ($path === '/api' || str_starts_with($path, '/api/')) {
    // index.php derives the route from REQUEST_URI; SCRIPT_NAME must look
    // like the front controller for its base-path stripping to be a no-op.
    $_SERVER['SCRIPT_NAME']     = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';

    require $root . '/index.php';

    return true;
}

/* ---- Admin dashboard ----------------------------------------------- */
if ($path === '/admin' || $path === '/admin/') {
    $_SERVER['SCRIPT_NAME']     = '/admin/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/admin/index.php';

    require $root . '/admin/index.php';

    return true;
}

/* ---- Everything else: let the built-in server serve the file -------- */
return false;
