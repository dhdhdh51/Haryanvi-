<?php

declare(strict_types=1);

namespace Haryanvi\Models;

use Haryanvi\Core\Crypto;

/**
 * Runtime configuration for one AI provider.
 *
 * The API key is held encrypted and only decrypted on demand, so a stray
 * var_dump of this object cannot leak the secret.
 */
final class ProviderConfig
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $driver,
        public readonly string $baseUrl,
        public readonly string $model,
        private readonly ?string $apiKeyCipher,
        public readonly float $temperature,
        public readonly int $maxTokens,
        public readonly int $timeoutSeconds,
        public readonly int $maxRetries,
        /** @var array<string,string> */
        public readonly array $extraHeaders,
        public readonly int $priority,
        public readonly bool $isDefault,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $headers = [];
        $rawHeaders = $row['extra_headers'] ?? null;
        if (is_string($rawHeaders) && $rawHeaders !== '') {
            $decoded = json_decode($rawHeaders, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && is_scalar($value)) {
                        $headers[$key] = (string) $value;
                    }
                }
            }
        }

        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['slug'],
            (string) $row['driver'],
            rtrim((string) $row['base_url'], '/'),
            (string) $row['model'],
            isset($row['api_key_cipher']) && is_string($row['api_key_cipher']) && $row['api_key_cipher'] !== ''
                ? $row['api_key_cipher']
                : null,
            (float) $row['temperature'],
            (int) $row['max_tokens'],
            (int) $row['timeout_seconds'],
            (int) $row['max_retries'],
            $headers,
            (int) $row['priority'],
            (bool) ($row['is_default'] ?? false),
        );
    }

    public function hasApiKey(): bool
    {
        return $this->apiKeyCipher !== null;
    }

    /**
     * @throws \RuntimeException when the stored secret cannot be decrypted
     */
    public function apiKey(): string
    {
        if ($this->apiKeyCipher === null) {
            throw new \RuntimeException(
                sprintf('Provider "%s" has no API key stored.', $this->name)
            );
        }

        return Crypto::decrypt($this->apiKeyCipher);
    }

    /**
     * Safe for logs and admin listings — never includes the secret.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'driver'      => $this->driver,
            'model'       => $this->model,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
            'priority'    => $this->priority,
            'has_key'     => $this->hasApiKey(),
        ];
    }
}
