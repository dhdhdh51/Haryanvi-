<?php

declare(strict_types=1);

namespace Haryanvi\Http\Middleware;

use Haryanvi\Core\RateLimiter;
use Haryanvi\Core\Request;
use Haryanvi\Core\RouteContext;
use Haryanvi\Services\SettingsService;

/**
 * Per-minute and per-hour caps on the translate endpoint.
 *
 * Runs after AuthMiddleware so the bucket is keyed to the account rather than
 * a shared mobile-carrier NAT address. This protects the operator's AI spend
 * independently of the longer-term daily/monthly quota.
 */
final class TranslateThrottleMiddleware implements Middleware
{
    public function handle(Request $request, RouteContext $context): void
    {
        $identity = (string) $context->userId();

        RateLimiter::hit(
            'translate.minute',
            $identity,
            SettingsService::getInt('rate_limit_per_minute', 20),
            60
        );

        RateLimiter::hit(
            'translate.hour',
            $identity,
            SettingsService::getInt('rate_limit_per_hour', 300),
            3600
        );
    }
}
