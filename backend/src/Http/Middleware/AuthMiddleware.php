<?php

declare(strict_types=1);

namespace Haryanvi\Http\Middleware;

use Haryanvi\Core\ApiException;
use Haryanvi\Core\Request;
use Haryanvi\Core\RouteContext;
use Haryanvi\Models\AuthenticatedUser;
use Haryanvi\Repositories\TokenRepository;
use Haryanvi\Repositories\UserRepository;

/**
 * Validates the bearer token and attaches the user to the request context.
 *
 * Blocked accounts are rejected here, so revoking access takes effect on the
 * very next request rather than when the token expires.
 */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, RouteContext $context): void
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            throw ApiException::unauthorized(
                'Send your access token in the Authorization header as "Bearer <token>".'
            );
        }

        $resolved = (new TokenRepository())->resolve($token);

        if ($resolved === null) {
            throw ApiException::unauthorized('Your session has expired. Please sign in again.');
        }

        $user = AuthenticatedUser::fromRow($resolved['user'], $resolved['token_id']);

        if ($user->status === 'blocked') {
            throw ApiException::forbidden('This account has been blocked. Contact support for help.');
        }

        if (!$user->isActive()) {
            throw ApiException::unauthorized('This account is no longer active.');
        }

        $context->setUser($user);

        // Throttled internally to one write every few minutes.
        (new TokenRepository())->touch($user->tokenId);
        (new UserRepository())->touchSeen($user->id);
    }
}
