<?php

declare(strict_types=1);

namespace Haryanvi\Providers;

use Haryanvi\Core\Logger;
use Haryanvi\Models\ProviderConfig;

/**
 * Shared HTTP plumbing: TLS-verified cURL, timeouts, bounded retries with
 * exponential backoff, and strict JSON decoding.
 *
 * Subclasses only describe the vendor's request/response shape.
 */
abstract class AbstractHttpProvider implements AiProvider
{
    public function __construct(protected readonly ProviderConfig $providerConfig)
    {
    }

    public function config(): ProviderConfig
    {
        return $this->providerConfig;
    }

    /**
     * Build the absolute endpoint URL for this request.
     */
    abstract protected function endpoint(): string;

    /**
     * Vendor-specific request body.
     *
     * @return array<string,mixed>
     */
    abstract protected function buildPayload(string $systemPrompt, string $userPrompt): array;

    /**
     * Pull the assistant text and token counts out of the decoded response.
     *
     * @param array<string,mixed> $decoded
     * @return array{content:string,tokens_in:int,tokens_out:int}
     */
    abstract protected function parseResponse(array $decoded): array;

    /**
     * Vendor-specific auth and version headers.
     *
     * @return list<string>
     */
    abstract protected function authHeaders(): array;

    public function complete(string $systemPrompt, string $userPrompt): ProviderResponse
    {
        $payload    = $this->buildPayload($systemPrompt, $userPrompt);
        $maxRetries = max(0, min(5, $this->providerConfig->maxRetries));
        $started    = microtime(true);

        $attempt   = 0;
        $lastError = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $decoded = $this->send($payload);
                $parsed  = $this->parseResponse($decoded);

                $content = trim($parsed['content']);
                if ($content === '') {
                    throw ProviderException::emptyResponse();
                }

                return new ProviderResponse(
                    $content,
                    $parsed['tokens_in'],
                    $parsed['tokens_out'],
                    (int) round((microtime(true) - $started) * 1000),
                    $attempt,
                    $this->providerConfig->model,
                );
            } catch (ProviderException $e) {
                $lastError = $e;

                if (!$e->isRetryable() || $attempt > $maxRetries) {
                    break;
                }

                Logger::warning('AI provider attempt failed, retrying', [
                    'provider' => $this->providerConfig->slug,
                    'attempt'  => $attempt,
                    'code'     => $e->errorCode(),
                ]);

                // Exponential backoff: 300ms, 600ms, 1200ms ... capped at 4s.
                usleep((int) min(4_000_000, 300_000 * (2 ** ($attempt - 1))));
            }
        }

        throw $lastError ?? ProviderException::transport('The AI request could not be completed.');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function send(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new ProviderException(
                'The request payload could not be encoded.',
                'PAYLOAD_ENCODE_FAILED',
                false
            );
        }

        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $this->authHeaders(),
        );

        foreach ($this->providerConfig->extraHeaders as $name => $value) {
            // Block header injection via admin-entered values.
            if (preg_match('/^[A-Za-z0-9\-]+$/', $name) === 1 && !str_contains($value, "\n")) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $handle = curl_init();

        if ($handle === false) {
            throw ProviderException::transport('cURL could not be initialised on this server.');
        }

        // Honour the configured timeout; clamp only to guard against 0,
        // negatives and values that would hang a web request. The admin form
        // recommends 5-120s, but a deliberate lower value is respected.
        $timeout = max(1, min(120, $this->providerConfig->timeoutSeconds));

        curl_setopt_array($handle, [
            CURLOPT_URL            => $this->endpoint(),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            // Transport security is non-negotiable: provider keys travel here.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'HaryanviAI-Backend/1.0',
        ]);

        $raw      = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno    = curl_errno($handle);
        $errorMsg = curl_error($handle);
        curl_close($handle);

        if ($errno !== 0 || !is_string($raw)) {
            if (in_array($errno, [CURLE_OPERATION_TIMEOUTED, 28], true)) {
                throw ProviderException::timeout($timeout);
            }

            throw ProviderException::transport(
                sprintf('Could not reach the AI provider (cURL %d: %s).', $errno, $errorMsg)
            );
        }

        if ($status < 200 || $status >= 300) {
            throw ProviderException::fromHttpStatus($status, $raw);
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw ProviderException::malformedResponse('response body was not a JSON object');
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /**
     * Safely read a nested value out of a decoded response.
     */
    protected static function dig(array $data, string ...$path): mixed
    {
        $cursor = $data;

        foreach ($path as $segment) {
            if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];

                continue;
            }

            return null;
        }

        return $cursor;
    }

    protected static function intOrZero(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
