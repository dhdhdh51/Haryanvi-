<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * JSON response writer.
 *
 * Every endpoint returns the same envelope so the Android client has exactly
 * one shape to parse:
 *
 *   success:  { "success": true,  "data": {...}, "meta": {...}? }
 *   failure:  { "success": false, "error": { "code": "...", "message": "...", "details": {...}? } }
 */
final class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<string,string> */
    private static array $extraHeaders = [];

    public static function addHeader(string $name, string $value): void
    {
        self::$extraHeaders[$name] = $value;
    }

    /**
     * @param array<string,mixed>|list<mixed> $data
     * @param array<string,mixed>             $meta
     */
    public static function success(array $data = [], int $status = 200, array $meta = []): never
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        self::emit($payload, $status);
    }

    /** @param array<string,mixed> $details */
    public static function error(
        string $code,
        string $message,
        int $status = 400,
        array $details = []
    ): never {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        self::emit(['success' => false, 'error' => $error], $status);
    }

    public static function fromException(ApiException $e): never
    {
        if ($e->errorCode() === 'RATE_LIMITED') {
            $retry = $e->details()['retry_after'] ?? null;
            if (is_int($retry)) {
                self::addHeader('Retry-After', (string) $retry);
            }
        }

        self::error($e->errorCode(), $e->getMessage(), $e->status(), $e->details());
    }

    public static function noContent(): never
    {
        self::sendHeaders(204);
        exit;
    }

    /** @param array<string,mixed> $payload */
    private static function emit(array $payload, int $status): never
    {
        self::sendHeaders($status);

        $json = json_encode($payload, self::JSON_FLAGS);

        if ($json === false) {
            // Last-ditch fallback: never emit a truncated or empty body.
            http_response_code(500);
            echo '{"success":false,"error":{"code":"ENCODING_ERROR",'
               . '"message":"The response could not be encoded."}}';
            exit;
        }

        echo $json;
        exit;
    }

    private static function sendHeaders(int $status): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // Translations are user-specific and must never be cached by proxies.
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        // Not a browser surface, but cheap defence in depth.
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

        // Hide the PHP version from responses where the SAPI allows it.
        header_remove('X-Powered-By');

        foreach (self::$extraHeaders as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
