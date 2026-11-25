<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * Any failure the client is allowed to see.
 *
 * Carries a stable machine-readable `errorCode` alongside the HTTP status so
 * the Android app can branch on the cause rather than parsing messages.
 */
class ApiException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 400,
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /* ----------------------------------------------------------------
     | Named constructors for the cases the API actually returns
     * -------------------------------------------------------------- */

    /** @param array<string,mixed> $errors */
    public static function validation(string $message = 'The submitted data is invalid.', array $errors = []): self
    {
        return new self('VALIDATION_FAILED', $message, 422, $errors === [] ? [] : ['fields' => $errors]);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self('UNAUTHORIZED', $message, 401);
    }

    public static function forbidden(string $message = 'You do not have access to this resource.'): self
    {
        return new self('FORBIDDEN', $message, 403);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self('NOT_FOUND', $message, 404);
    }

    public static function methodNotAllowed(string $message = 'HTTP method not allowed for this endpoint.'): self
    {
        return new self('METHOD_NOT_ALLOWED', $message, 405);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }

    public static function payloadTooLarge(string $message = 'Request body is too large.'): self
    {
        return new self('PAYLOAD_TOO_LARGE', $message, 413);
    }

    public static function rateLimited(string $message, int $retryAfterSeconds): self
    {
        return new self('RATE_LIMITED', $message, 429, ['retry_after' => $retryAfterSeconds]);
    }

    public static function quotaExceeded(string $message, array $details = []): self
    {
        return new self('QUOTA_EXCEEDED', $message, 429, $details);
    }

    /**
     * Raised when no AI provider is active/configured. The spec requires a
     * clear configuration error here rather than any fabricated translation.
     */
    public static function providerNotConfigured(
        string $message = 'No AI provider is configured. Add and activate a provider in the admin dashboard.'
    ): self {
        return new self('AI_PROVIDER_NOT_CONFIGURED', $message, 503);
    }

    public static function providerFailure(string $message, array $details = []): self
    {
        return new self('AI_PROVIDER_FAILED', $message, 502, $details);
    }

    public static function maintenance(string $message): self
    {
        return new self('MAINTENANCE_MODE', $message, 503);
    }

    public static function httpsRequired(): self
    {
        return new self('HTTPS_REQUIRED', 'This API must be called over HTTPS.', 403);
    }

    public static function server(string $message = 'An unexpected server error occurred.'): self
    {
        return new self('SERVER_ERROR', $message, 500);
    }
}
