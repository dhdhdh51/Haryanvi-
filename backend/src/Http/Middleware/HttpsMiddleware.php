<?php

declare(strict_types=1);

namespace Haryanvi\Http\Middleware;

use Haryanvi\Core\ApiException;
use Haryanvi\Core\Config;
use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\RouteContext;

/**
 * Refuses plain-HTTP traffic and sets HSTS on secure responses.
 *
 * Bearer tokens and user text travel on these requests, so the check is on by
 * default and can only be disabled in config (useful for a local
 * `php -S` run during development).
 */
final class HttpsMiddleware implements Middleware
{
    public function handle(Request $request, RouteContext $context): void
    {
        if (!Config::getBool('security.force_https', true)) {
            return;
        }

        if (!$request->isSecure()) {
            throw ApiException::httpsRequired();
        }

        // 180 days, subdomains included.
        Response::addHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
    }
}
