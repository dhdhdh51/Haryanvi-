<?php

declare(strict_types=1);

namespace Haryanvi\Repositories;

use Haryanvi\Core\Crypto;
use Haryanvi\Core\Database;
use Haryanvi\Models\ProviderConfig;

/**
 * AI provider configuration storage.
 *
 * API keys are encrypted on the way in and never returned in plaintext to
 * any admin screen — only the last four characters are shown for recognition.
 */
final class ProviderRepository
{
    private const COLUMNS =
        'id, name, slug, driver, base_url, model, api_key_cipher, api_key_last4,
         temperature, max_tokens, timeout_seconds, max_retries, extra_headers,
         is_active, is_default, priority, last_error, last_used_at';

    /**
     * Active providers in the order they should be attempted: the default
     * first, then ascending priority. This list *is* the fallback chain.
     *
     * @return list<ProviderConfig>
     */
    public function activeChain(): array
    {
        $rows = Database::fetchAll(
            'SELECT ' . self::COLUMNS . '
             FROM ai_providers
             WHERE is_active = 1 AND api_key_cipher IS NOT NULL AND api_key_cipher <> \'\'
             ORDER BY is_default DESC, priority ASC, id ASC'
        );

        return array_map(static fn (array $row): ProviderConfig => ProviderConfig::fromRow($row), $rows);
    }

    public function find(int $id): ?ProviderConfig
    {
        $row = Database::fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM ai_providers WHERE id = :id',
            ['id' => $id]
        );

        return $row === null ? null : ProviderConfig::fromRow($row);
    }

    /** @return list<array<string,mixed>> */
    public function allForAdmin(): array
    {
        return Database::fetchAll(
            'SELECT id, name, slug, driver, base_url, model, api_key_last4, temperature,
                    max_tokens, timeout_seconds, max_retries, is_active, is_default,
                    priority, last_error, last_used_at,
                    (api_key_cipher IS NOT NULL AND api_key_cipher <> \'\') AS has_key
             FROM ai_providers
             ORDER BY is_default DESC, priority ASC, name ASC'
        );
    }

    /** @return array<string,mixed>|null */
    public function findRaw(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT id, name, slug, driver, base_url, model, api_key_last4, temperature,
                    max_tokens, timeout_seconds, max_retries, extra_headers,
                    is_active, is_default, priority,
                    (api_key_cipher IS NOT NULL AND api_key_cipher <> \'\') AS has_key
             FROM ai_providers WHERE id = :id',
            ['id' => $id]
        );
    }

    public function hasAnyActive(): bool
    {
        return Database::fetchValue(
            'SELECT 1 FROM ai_providers
             WHERE is_active = 1 AND api_key_cipher IS NOT NULL AND api_key_cipher <> \'\'
             LIMIT 1'
        ) !== null;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM ai_providers WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($exceptId !== null) {
            $sql           .= ' AND id <> :id';
            $params['id']   = $exceptId;
        }

        return Database::fetchValue($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * @param array<string,mixed> $data
     * @param string|null         $apiKey plaintext; encrypted here, never stored raw
     */
    public function create(array $data, ?string $apiKey): int
    {
        $data['api_key_cipher'] = $apiKey === null || $apiKey === '' ? null : Crypto::encrypt($apiKey);
        $data['api_key_last4']  = $apiKey === null || $apiKey === '' ? null : substr($apiKey, -4);

        Database::execute(
            'INSERT INTO ai_providers
                (name, slug, driver, base_url, model, api_key_cipher, api_key_last4,
                 temperature, max_tokens, timeout_seconds, max_retries, extra_headers,
                 is_active, priority)
             VALUES
                (:name, :slug, :driver, :base_url, :model, :api_key_cipher, :api_key_last4,
                 :temperature, :max_tokens, :timeout_seconds, :max_retries, :extra_headers,
                 :is_active, :priority)',
            $data
        );

        return Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param string|null         $apiKey null = leave the stored key untouched
     */
    public function update(int $id, array $data, ?string $apiKey): void
    {
        $data['id'] = $id;

        $keySql = '';
        if ($apiKey !== null && $apiKey !== '') {
            $keySql                 = ', api_key_cipher = :api_key_cipher, api_key_last4 = :api_key_last4';
            $data['api_key_cipher'] = Crypto::encrypt($apiKey);
            $data['api_key_last4']  = substr($apiKey, -4);
        }

        Database::execute(
            'UPDATE ai_providers
             SET name = :name, driver = :driver, base_url = :base_url, model = :model,
                 temperature = :temperature, max_tokens = :max_tokens,
                 timeout_seconds = :timeout_seconds, max_retries = :max_retries,
                 extra_headers = :extra_headers, is_active = :is_active, priority = :priority
                 ' . $keySql . '
             WHERE id = :id',
            $data
        );
    }

    public function setActive(int $id, bool $active): void
    {
        Database::execute(
            'UPDATE ai_providers SET is_active = :active WHERE id = :id',
            ['active' => $active ? 1 : 0, 'id' => $id]
        );
    }

    public function setDefault(int $id): void
    {
        Database::transaction(static function () use ($id): void {
            Database::execute('UPDATE ai_providers SET is_default = 0 WHERE is_default = 1');
            Database::execute(
                'UPDATE ai_providers SET is_default = 1, is_active = 1 WHERE id = :id',
                ['id' => $id]
            );
        });
    }

    public function delete(int $id): void
    {
        Database::execute('DELETE FROM ai_providers WHERE id = :id', ['id' => $id]);
    }

    public function recordSuccess(int $id): void
    {
        Database::execute(
            'UPDATE ai_providers SET last_used_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = :id',
            ['id' => $id]
        );
    }

    public function recordError(int $id, string $message): void
    {
        Database::execute(
            'UPDATE ai_providers SET last_error = :message, last_used_at = UTC_TIMESTAMP() WHERE id = :id',
            ['message' => mb_substr($message, 0, 1000), 'id' => $id]
        );
    }
}
