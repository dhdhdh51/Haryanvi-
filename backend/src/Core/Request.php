<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * Immutable view of the incoming HTTP request.
 *
 * Body parsing is strict: the API accepts JSON only, is capped at
 * `security.max_body_bytes`, and rejects malformed payloads outright.
 */
final class Request
{
    /** @param array<string,mixed> $body */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly string $ip,
        private readonly bool $secure,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $headers = self::readHeaders();

        return new self(
            $method,
            self::resolvePath(),
            self::readQuery(),
            self::readBody($method, $headers),
            $headers,
            self::resolveIp(),
            self::detectHttps(),
        );
    }

    /* ----------------------------------------------------------------
     | Path resolution
     * -------------------------------------------------------------- */

    /**
     * Produces a leading-slash path with any subdirectory prefix removed, so
     * the same router works whether the backend sits at the domain root or
     * under something like /haryanvi/.
     */
    private static function resolvePath(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        // Directory containing the front controller, e.g. "" or "/haryanvi".
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $base       = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($base !== '' && $base !== '.' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @return array<string,string> */
    private static function readHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            /** @var array<string,string>|false $raw */
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    $headers[strtolower((string) $key)] = (string) $value;
                }
            }
        }

        // CGI/FastCGI fallback — also covers servers where getallheaders() is absent.
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] ??= (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_scalar($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] ??= (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH']) && is_scalar($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] ??= (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /** @return array<string,mixed> */
    private static function readQuery(): array
    {
        /** @var array<string,mixed> $query */
        $query = $_GET ?? [];

        return $query;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private static function readBody(string $method, array $headers): array
    {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return [];
        }

        $maxBytes = Config::isLoaded() ? Config::getInt('security.max_body_bytes', 65536) : 65536;

        $declared = (int) ($headers['content-length'] ?? 0);
        if ($declared > $maxBytes) {
            throw ApiException::payloadTooLarge(
                sprintf('Request body exceeds the %d byte limit.', $maxBytes)
            );
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        if (strlen($raw) > $maxBytes) {
            throw ApiException::payloadTooLarge(
                sprintf('Request body exceeds the %d byte limit.', $maxBytes)
            );
        }

        $contentType = strtolower($headers['content-type'] ?? '');

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $parsed = [];
            parse_str($raw, $parsed);

            /** @var array<string,mixed> $parsed */
            return $parsed;
        }

        // Default to JSON — the documented content type for this API.
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(
                'MALFORMED_JSON',
                'Request body is not valid JSON.',
                400,
                [],
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException('MALFORMED_JSON', 'Request body must be a JSON object.', 400);
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    private static function resolveIp(): string
    {
        // Shared hosting commonly sits behind a proxy; prefer the forwarded
        // chain's first entry but validate it before use.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = $_SERVER[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $candidate = trim(explode(',', $value)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    private static function detectHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

        return is_string($proto) && strtolower($proto) === 'https';
    }

    /* ----------------------------------------------------------------
     | Accessors
     * -------------------------------------------------------------- */

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent', '') ?? '', 0, 255);
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null) {
            return null;
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return null;
        }

        return $m[1];
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
