<?php

declare(strict_types=1);

namespace Haryanvi\Http\Controllers;

use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\RouteContext;
use Haryanvi\Services\PasswordHasher;
use Haryanvi\Services\QuotaService;

/**
 * GET /api/v1/usage
 *
 * Powers the account screen's allowance display. Informational only — the
 * authoritative check happens inside QuotaService on every translate call.
 */
final class UsageController
{
    public function __construct(private readonly QuotaService $quota = new QuotaService())
    {
    }

    public function show(Request $request, RouteContext $context): void
    {
        $user = $context->user();

        Response::success([
            'account' => $user->toArray(),
            'usage'   => $this->quota->snapshot($user),
        ]);
    }

    /**
     * GET /api/v1/health — unauthenticated readiness probe.
     *
     * Reports whether the pieces an operator needs to get right are in place,
     * without revealing versions, paths or credentials.
     */
    public function health(Request $request, RouteContext $context): void
    {
        $providers = new \Haryanvi\Repositories\ProviderRepository();

        Response::success([
            'status'             => 'ok',
            'database'           => \Haryanvi\Core\Database::isConnected() ? 'connected' : 'unavailable',
            'ai_provider'        => $providers->hasAnyActive() ? 'configured' : 'not_configured',
            'encryption_key'     => \Haryanvi\Core\Crypto::isConfigured() ? 'present' : 'missing',
            'password_algorithm' => PasswordHasher::algorithmName(),
            'api_version'        => 'v1',
            'server_time'        => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
