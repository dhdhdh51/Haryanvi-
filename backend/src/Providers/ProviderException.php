<?php

declare(strict_types=1);

namespace Haryanvi\Providers;

/**
 * A provider-level failure.
 *
 * `retryable` tells the translation service whether to retry the same provider
 * (transient: timeout, 5xx, 429) or move straight to the fallback
 * (permanent: bad key, unknown model, malformed request).
 */
final class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly bool $retryable,
        private readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public static function transport(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 'TRANSPORT_ERROR', true, null, $previous);
    }

    public static function timeout(int $seconds): self
    {
        return new self(
            sprintf('The AI provider did not respond within %d seconds.', $seconds),
            'TIMEOUT',
            true
        );
    }

    public static function fromHttpStatus(int $status, string $body): self
    {
        $snippet = self::snippet($body);

        return match (true) {
            $status === 401 || $status === 403 => new self(
                'The AI provider rejected the API key. Check the credentials in the admin dashboard.',
                'INVALID_CREDENTIALS',
                false,
                $status
            ),
            $status === 404 => new self(
                'The AI provider endpoint or model was not found. Check the base URL and model name.',
                'ENDPOINT_NOT_FOUND',
                false,
                $status
            ),
            $status === 429 => new self(
                'The AI provider is rate limiting this account.',
                'PROVIDER_RATE_LIMITED',
                true,
                $status
            ),
            $status >= 500 => new self(
                sprintf('The AI provider returned a server error (HTTP %d).', $status),
                'PROVIDER_SERVER_ERROR',
                true,
                $status
            ),
            default => new self(
                sprintf('The AI provider rejected the request (HTTP %d): %s', $status, $snippet),
                'PROVIDER_BAD_REQUEST',
                false,
                $status
            ),
        };
    }

    public static function malformedResponse(string $detail): self
    {
        return new self(
            'The AI provider returned a response this server could not read: ' . $detail,
            'MALFORMED_PROVIDER_RESPONSE',
            true
        );
    }

    public static function emptyResponse(): self
    {
        return new self(
            'The AI provider returned an empty translation.',
            'EMPTY_PROVIDER_RESPONSE',
            true
        );
    }

    private static function snippet(string $body): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($body)) ?? '';

        return mb_substr($clean, 0, 200);
    }
}
