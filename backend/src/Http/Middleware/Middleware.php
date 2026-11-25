<?php

declare(strict_types=1);

namespace Haryanvi\Http\Middleware;

use Haryanvi\Core\Request;
use Haryanvi\Core\RouteContext;

/**
 * A pre-handler check.
 *
 * Implementations either pass silently, enrich the RouteContext (as
 * AuthMiddleware does), or throw an ApiException to stop the request.
 */
interface Middleware
{
    public function handle(Request $request, RouteContext $context): void;
}
