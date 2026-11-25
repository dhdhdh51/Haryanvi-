<?php

declare(strict_types=1);

namespace Haryanvi\Tests;

/**
 * Minimal HTTP client for the integration suite.
 */
final class HttpClient
{
    private ?string $token = null;

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function withToken(?string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    /** @param array<string,mixed> $body */
    public function post(string $path, array $body = [], array $headers = []): HttpResponse
    {
        return $this->send('POST', $path, $body, $headers);
    }

    /** @param array<string,mixed> $body */
    public function patch(string $path, array $body = [], array $headers = []): HttpResponse
    {
        return $this->send('PATCH', $path, $body, $headers);
    }

    public function get(string $path, array $headers = []): HttpResponse
    {
        return $this->send('GET', $path, null, $headers);
    }

    /** @param array<string,mixed>|null $body */
    public function delete(string $path, ?array $body = null, array $headers = []): HttpResponse
    {
        return $this->send('DELETE', $path, $body, $headers);
    }

    /**
     * Send a deliberately malformed body — used to test the JSON guard.
     */
    public function postRaw(string $path, string $rawBody, string $contentType = 'application/json'): HttpResponse
    {
        return $this->execute('POST', $path, $rawBody, ['Content-Type: ' . $contentType]);
    }

    /** @param array<string,mixed>|null $body */
    private function send(string $method, string $path, ?array $body, array $headers): HttpResponse
    {
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE);

        $allHeaders = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);

        return $this->execute($method, $path, $encoded, $allHeaders);
    }

    private function execute(string $method, string $path, ?string $body, array $headers): HttpResponse
    {
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $handle = curl_init($this->baseUrl . $path);

        if ($handle === false) {
            throw new \RuntimeException('Could not initialise cURL.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $raw        = curl_exec($handle);
        $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error      = curl_error($handle);
        curl_close($handle);

        if (!is_string($raw)) {
            throw new \RuntimeException('Request to ' . $path . ' failed: ' . $error);
        }

        return new HttpResponse(
            $status,
            substr($raw, 0, $headerSize),
            substr($raw, $headerSize)
        );
    }
}
