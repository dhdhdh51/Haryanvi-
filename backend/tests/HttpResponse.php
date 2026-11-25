<?php

declare(strict_types=1);

namespace Haryanvi\Tests;

/**
 * Decoded API response with helpers matching the project's JSON envelope.
 */
final class HttpResponse
{
    /** @var array<string,mixed> */
    public readonly array $json;

    public function __construct(
        public readonly int $status,
        public readonly string $rawHeaders,
        public readonly string $rawBody,
    ) {
        $decoded    = json_decode($rawBody, true);
        $this->json = is_array($decoded) ? $decoded : [];
    }

    public function isSuccess(): bool
    {
        return ($this->json['success'] ?? false) === true;
    }

    /**
     * Read a dotted path out of the `data` object.
     */
    public function data(string $path = '', mixed $default = null): mixed
    {
        return $this->dig($this->json['data'] ?? [], $path, $default);
    }

    public function meta(string $path = '', mixed $default = null): mixed
    {
        return $this->dig($this->json['meta'] ?? [], $path, $default);
    }

    public function errorCode(): ?string
    {
        $code = $this->json['error']['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    public function errorMessage(): string
    {
        $message = $this->json['error']['message'] ?? '';

        return is_string($message) ? $message : '';
    }

    /** @return array<string,mixed> */
    public function errorDetails(): array
    {
        $details = $this->json['error']['details'] ?? [];

        return is_array($details) ? $details : [];
    }

    public function hasHeader(string $name): bool
    {
        return stripos($this->rawHeaders, "\n" . $name . ':') !== false
            || stripos($this->rawHeaders, $name . ':') === 0;
    }

    public function describe(): string
    {
        return sprintf(
            'HTTP %d %s',
            $this->status,
            $this->isSuccess() ? 'ok' : ((string) $this->errorCode() . ' / ' . $this->errorMessage())
        );
    }

    private function dig(mixed $source, string $path, mixed $default): mixed
    {
        if ($path === '') {
            return $source;
        }

        $cursor = $source;

        foreach (explode('.', $path) as $segment) {
            if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];

                continue;
            }

            return $default;
        }

        return $cursor;
    }
}
