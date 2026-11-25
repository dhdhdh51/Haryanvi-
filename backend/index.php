<?php
/**
 * Haryanvi AI — REST API front controller.
 *
 * Upload the contents of backend/ into your web root (public_html) and the
 * API answers at https://your-domain/api/v1/...
 *
 * Routing is handled by .htaccess, which forwards /api/* here. All responses
 * are JSON in the envelope documented in docs/API.md.
 */

declare(strict_types=1);

use Haryanvi\Core\ApiException;
use Haryanvi\Core\Logger;
use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\Router;
use Haryanvi\Http\Controllers\AuthController;
use Haryanvi\Http\Controllers\DialectController;
use Haryanvi\Http\Controllers\FeedbackController;
use Haryanvi\Http\Controllers\HistoryController;
use Haryanvi\Http\Controllers\SettingsController;
use Haryanvi\Http\Controllers\TranslateController;
use Haryanvi\Http\Controllers\UsageController;
use Haryanvi\Http\Middleware\AuthMiddleware;
use Haryanvi\Http\Middleware\CorsMiddleware;
use Haryanvi\Http\Middleware\HttpsMiddleware;
use Haryanvi\Http\Middleware\MaintenanceMiddleware;
use Haryanvi\Http\Middleware\TranslateThrottleMiddleware;

require __DIR__ . '/src/bootstrap.php';

$router = new Router();

/* ---------------------------------------------------------------------
 | Global middleware — runs on every matched route, in this order
 * ------------------------------------------------------------------- */
$router->addGlobalMiddleware(CorsMiddleware::class);
$router->addGlobalMiddleware(HttpsMiddleware::class);
$router->addGlobalMiddleware(MaintenanceMiddleware::class);

/* ---------------------------------------------------------------------
 | Public endpoints
 * ------------------------------------------------------------------- */
$router->get('/api/v1/health',   [UsageController::class, 'health']);
$router->get('/api/v1/dialects', [DialectController::class, 'index']);
$router->get('/api/v1/settings', [SettingsController::class, 'show']);

$router->post('/api/v1/auth/register', [AuthController::class, 'register']);
$router->post('/api/v1/auth/login',    [AuthController::class, 'login']);

/* ---------------------------------------------------------------------
 | Authenticated endpoints
 * ------------------------------------------------------------------- */
$auth = [AuthMiddleware::class];

$router->post('/api/v1/auth/logout',         [AuthController::class, 'logout'],         $auth);
$router->post('/api/v1/auth/password',       [AuthController::class, 'changePassword'], $auth);
$router->post('/api/v1/auth/delete-account', [AuthController::class, 'deleteAccount'],  $auth);

// The only endpoint that calls an AI provider, so it carries the extra throttle.
$router->post('/api/v1/translate', [TranslateController::class, 'translate'], [
    AuthMiddleware::class,
    TranslateThrottleMiddleware::class,
]);

$router->patch('/api/v1/settings', [SettingsController::class, 'update'], $auth);

$router->get('/api/v1/history',         [HistoryController::class, 'index'],   $auth);
$router->post('/api/v1/history',        [HistoryController::class, 'store'],   $auth);
$router->patch('/api/v1/history/{id}',  [HistoryController::class, 'update'],  $auth);
$router->delete('/api/v1/history/{id}', [HistoryController::class, 'destroy'], $auth);
$router->delete('/api/v1/history',      [HistoryController::class, 'clear'],   $auth);

$router->post('/api/v1/feedback', [FeedbackController::class, 'store'], $auth);
$router->get('/api/v1/usage',     [UsageController::class, 'show'],     $auth);

/* ---------------------------------------------------------------------
 | Dispatch
 * ------------------------------------------------------------------- */
try {
    $router->dispatch(Request::capture());
} catch (ApiException $e) {
    if ($e->status() >= 500) {
        Logger::error('API error', ['code' => $e->errorCode(), 'message' => $e->getMessage()]);
    }

    Response::fromException($e);
} catch (\Throwable $e) {
    // Anything unhandled is a bug: log it in full, tell the client nothing.
    Logger::exception($e);

    if (\Haryanvi\Core\Config::isDebug()) {
        Response::error('SERVER_ERROR', $e->getMessage(), 500, [
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    Response::error(
        'SERVER_ERROR',
        'An unexpected server error occurred. Please try again.',
        500
    );
}
