<?php

declare(strict_types=1);

namespace Haryanvi\Tests;

/**
 * Cookie-aware HTML client for exercising the admin dashboard.
 *
 * Keeps a cookie jar so sessions persist across requests, and does not follow
 * redirects automatically — the tests assert on the 303s themselves.
 */
final class BrowserClient
{
    private string $cookieJar;

    public function __construct(private readonly string $baseUrl)
    {
        $jar = tempnam(sys_get_temp_dir(), 'hy-admin-cookies-');

        if ($jar === false) {
            throw new \RuntimeException('Could not create a cookie jar.');
        }

        $this->cookieJar = $jar;
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    public function get(string $path): BrowserResponse
    {
        return $this->send('GET', $path, null);
    }

    /** @param array<string,string> $fields */
    public function post(string $path, array $fields): BrowserResponse
    {
        return $this->send('POST', $path, http_build_query($fields));
    }

    /**
     * Pull the CSRF token out of a rendered page so forms can be submitted
     * the way a browser would.
     */
    public function csrfTokenFrom(BrowserResponse $response): string
    {
        if (preg_match('/name="csrf"\s+value="([a-f0-9]{64})"/i', $response->body, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    public function hasSessionCookie(): bool
    {
        $contents = is_file($this->cookieJar) ? (string) file_get_contents($this->cookieJar) : '';

        return str_contains($contents, 'hy_admin_sess');
    }

    private function send(string $method, string $path, ?string $body): BrowserResponse
    {
        $handle = curl_init($this->baseUrl . $path);

        if ($handle === false) {
            throw new \RuntimeException('Could not initialise cURL.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_USERAGENT      => 'HaryanviAI-AdminTest/1.0',
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
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

        return new BrowserResponse(
            $status,
            substr($raw, 0, $headerSize),
            substr($raw, $headerSize)
        );
    }
}
