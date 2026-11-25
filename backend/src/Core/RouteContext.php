<?php

declare(strict_types=1);

namespace Haryanvi\Core;

use Haryanvi\Models\AuthenticatedUser;

/**
 * Per-request scratch space shared between middleware and the controller.
 *
 * Holds the matched URL parameters and — once AuthMiddleware has run — the
 * authenticated user.
 */
final class RouteContext
{
    private ?AuthenticatedUser $user = null;

    /** @param array<string,string> $params */
    public function __construct(
        private readonly array $params = [],
        private readonly string $routeName = '',
    ) {
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    public function intParam(string $key): int
    {
        $value = $this->params[$key] ?? '';

        return ctype_digit($value) ? (int) $value : 0;
    }

    /** @return array<string,string> */
    public function params(): array
    {
        return $this->params;
    }

    public function routeName(): string
    {
        return $this->routeName;
    }

    public function setUser(AuthenticatedUser $user): void
    {
        $this->user = $user;
    }

    public function hasUser(): bool
    {
        return $this->user instanceof AuthenticatedUser;
    }

    /**
     * Guaranteed non-null on any route protected by AuthMiddleware.
     */
    public function user(): AuthenticatedUser
    {
        if (!$this->user instanceof AuthenticatedUser) {
            throw ApiException::unauthorized();
        }

        return $this->user;
    }

    public function userId(): int
    {
        return $this->user()->id;
    }
}
