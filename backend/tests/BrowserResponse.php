<?php

declare(strict_types=1);

namespace Haryanvi\Tests;

/**
 * An HTML response from the admin dashboard.
 */
final class BrowserResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $headers,
        public readonly string $body,
    ) {
    }

    public function redirectTarget(): ?string
    {
        if (preg_match('/^location:\s*(.+)$/im', $this->headers, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    public function header(string $name): ?string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/im', $this->headers, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    public function contains(string $needle): bool
    {
        return str_contains($this->body, $needle);
    }

    /**
     * True when the dashboard rendered its generic failure page.
     */
    public function isErrorPage(): bool
    {
        return $this->status >= 500 || str_contains($this->body, 'Something went wrong');
    }

    /**
     * PHP notices and warnings leaking into the HTML would indicate a bug
     * even when the status code is 200.
     */
    public function hasPhpError(): bool
    {
        foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $marker) {
            if (str_contains($this->body, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function describe(): string
    {
        $snippet = preg_replace('/\s+/', ' ', strip_tags($this->body)) ?? '';

        return sprintf('HTTP %d — %s', $this->status, mb_substr(trim($snippet), 0, 160));
    }
}
