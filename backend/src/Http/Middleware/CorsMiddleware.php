<?php

declare(strict_types=1);

namespace Haryanvi\Http\Middleware;

use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\RouteContext;
use Haryanvi\Services\SettingsService;

/**
 * Strict CORS.
 *
 * The default is to send no CORS headers at all, which blocks every browser
 * cross-origin call. The native Android client is unaffected because CORS is
 * a browser mechanism. An operator who ships a web client adds specific
 * origins in the admin dashboard; wildcards are never emitted.
 */
final class CorsMiddleware implements Middleware
{
    public function handle(Request $request, RouteContext $context): void
    {
        $origin = $request->header('origin');

        // No Origin header: not a browser cross-origin request.
        if ($origin === null || $origin === '') {
            $this->terminatePreflight($request);

            return;
        }

        $allowed = SettingsService::corsOrigins();

        if (in_array($origin, $allowed, true)) {
            Response::addHeader('Access-Control-Allow-Origin', $origin);
            Response::addHeader('Vary', 'Origin');
            Response::addHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
            Response::addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Client-Channel');
            Response::addHeader('Access-Control-Max-Age', '600');
        }

        $this->terminatePreflight($request);
    }

    /**
     * Answer a preflight immediately — headers already queued above decide
     * whether the browser proceeds.
     */
    private function terminatePreflight(Request $request): void
    {
        if ($request->method() === 'OPTIONS') {
            Response::noContent();
        }
    }
}
