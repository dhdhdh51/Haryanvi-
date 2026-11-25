<?php

declare(strict_types=1);

namespace Haryanvi\Http\Middleware;

use Haryanvi\Core\ApiException;
use Haryanvi\Core\Request;
use Haryanvi\Core\RouteContext;
use Haryanvi\Services\SettingsService;

/**
 * Returns 503 for everything while maintenance mode is on.
 *
 * `/settings` stays reachable so the app can read the operator's maintenance
 * message and show it instead of a generic network error.
 */
final class MaintenanceMiddleware implements Middleware
{
    private const ALWAYS_ALLOWED = [
        '/api/v1/settings',
        '/api/v1/health',
    ];

    public function handle(Request $request, RouteContext $context): void
    {
        if (in_array($request->path(), self::ALWAYS_ALLOWED, true)) {
            return;
        }

        if (SettingsService::isMaintenanceMode()) {
            throw ApiException::maintenance(SettingsService::maintenanceMessage());
        }
    }
}
