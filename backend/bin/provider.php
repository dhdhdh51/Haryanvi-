<?php
/**
 * AI provider management from the command line.
 *
 *   php bin/provider.php list
 *   php bin/provider.php add --name="OpenAI" --driver=openai_compatible \
 *        --model=gpt-4o-mini --base-url=https://api.openai.com/v1 --key=sk-...
 *   php bin/provider.php activate --id=1
 *   php bin/provider.php deactivate --id=1
 *   php bin/provider.php default --id=1
 *   php bin/provider.php test --id=1
 *   php bin/provider.php delete --id=1
 *
 * The API key is encrypted with the key in config/config.php before it
 * reaches the database. It is never printed back.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Haryanvi\Core\Crypto;
use Haryanvi\Models\Dialect;
use Haryanvi\Models\TonePreset;
use Haryanvi\Models\TranslationRequest;
use Haryanvi\Providers\ProviderFactory;
use Haryanvi\Repositories\ProviderRepository;
use Haryanvi\Services\PromptBuilder;
use Haryanvi\Services\TextGuard;

/** @return array<string,string> */
function options(array $argv): array
{
    $parsed = [];

    foreach (array_slice($argv, 2) as $argument) {
        if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $argument, $m) === 1) {
            $parsed[$m[1]] = $m[2] ?? '1';
        }
    }

    return $parsed;
}

function slugify(string $value): string
{
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? ''));

    return trim($slug, '-') ?: 'provider';
}

$command    = $argv[1] ?? 'list';
$options    = options($argv);
$repository = new ProviderRepository();

try {
    if (!Crypto::isConfigured() && in_array($command, ['add', 'test'], true)) {
        throw new RuntimeException(
            'security.encryption_key is not set in config/config.php. Run: php bin/generate-key.php'
        );
    }

    switch ($command) {
        case 'list':
            $rows = $repository->allForAdmin();

            if ($rows === []) {
                echo "\nNo AI providers configured.\n";
                echo "Add one with:  php bin/provider.php add --help\n\n";
                break;
            }

            echo "\n";
            printf(
                "%-4s %-22s %-20s %-24s %-7s %-8s %-7s\n",
                'ID', 'NAME', 'DRIVER', 'MODEL', 'KEY', 'ACTIVE', 'DEFAULT'
            );
            echo str_repeat('-', 100) . "\n";

            foreach ($rows as $row) {
                printf(
                    "%-4d %-22s %-20s %-24s %-7s %-8s %-7s\n",
                    (int) $row['id'],
                    mb_substr((string) $row['name'], 0, 22),
                    (string) $row['driver'],
                    mb_substr((string) $row['model'], 0, 24),
                    ((int) $row['has_key'] === 1 ? '…' . (string) $row['api_key_last4'] : 'none'),
                    ((int) $row['is_active'] === 1 ? 'yes' : 'no'),
                    ((int) $row['is_default'] === 1 ? 'yes' : '')
                );

                if (($row['last_error'] ?? null) !== null && trim((string) $row['last_error']) !== '') {
                    echo '     last error: ' . mb_substr((string) $row['last_error'], 0, 90) . "\n";
                }
            }

            echo "\nProviders are tried in order: default first, then ascending priority.\n\n";
            break;

        case 'add':
            if (isset($options['help'])) {
                echo "\nAvailable drivers:\n";
                foreach (ProviderFactory::DRIVERS as $code => $label) {
                    $defaults = ProviderFactory::DRIVER_DEFAULTS[$code];
                    echo sprintf(
                        "  %-20s %s\n    base-url: %s\n    model:    %s\n",
                        $code,
                        $label,
                        $defaults['base_url'],
                        $defaults['model']
                    );
                }
                echo "\n";
                break;
            }

            $name   = $options['name']   ?? '';
            $driver = $options['driver'] ?? 'openai_compatible';

            if ($name === '') {
                throw new RuntimeException('--name is required.');
            }

            if (!ProviderFactory::isValidDriver($driver)) {
                throw new RuntimeException(
                    '--driver must be one of: ' . implode(', ', ProviderFactory::driverCodes())
                );
            }

            $defaults = ProviderFactory::DRIVER_DEFAULTS[$driver];
            $baseUrl  = $options['base-url'] ?? $defaults['base_url'];
            $model    = $options['model']    ?? $defaults['model'];
            $apiKey   = $options['key']      ?? '';

            if ($apiKey === '') {
                throw new RuntimeException('--key is required (the provider API key).');
            }

            if (!str_starts_with(strtolower($baseUrl), 'https://')) {
                throw new RuntimeException('--base-url must use HTTPS.');
            }

            $slug = slugify($options['slug'] ?? $name);
            $suffix = 1;
            while ($repository->slugExists($slug)) {
                $slug = slugify($options['slug'] ?? $name) . '-' . (++$suffix);
            }

            $id = $repository->create([
                'name'            => $name,
                'slug'            => $slug,
                'driver'          => $driver,
                'base_url'        => rtrim($baseUrl, '/'),
                'model'           => $model,
                'temperature'     => (float) ($options['temperature'] ?? 0.3),
                'max_tokens'      => (int) ($options['max-tokens'] ?? 1024),
                'timeout_seconds' => (int) ($options['timeout'] ?? 30),
                'max_retries'     => (int) ($options['retries'] ?? 2),
                'extra_headers'   => $options['headers'] ?? null,
                'is_active'       => isset($options['inactive']) ? 0 : 1,
                'priority'        => (int) ($options['priority'] ?? 100),
            ], $apiKey);

            // First provider added becomes the default automatically.
            if (count($repository->allForAdmin()) === 1) {
                $repository->setDefault($id);
            }

            echo "\nProvider #" . $id . " (\"" . $name . "\") created and "
               . (isset($options['inactive']) ? 'left inactive' : 'activated') . ".\n";
            echo "The API key was encrypted before storage; only …" . substr($apiKey, -4) . " is recoverable.\n";
            echo "\nVerify it with:  php bin/provider.php test --id=" . $id . "\n\n";
            break;

        case 'update':
            $id      = (int) ($options['id'] ?? 0);
            $current = $id > 0 ? $repository->findRaw($id) : null;

            if ($current === null) {
                throw new RuntimeException('--id must reference an existing provider.');
            }

            $driver = $options['driver'] ?? (string) $current['driver'];

            if (!ProviderFactory::isValidDriver($driver)) {
                throw new RuntimeException(
                    '--driver must be one of: ' . implode(', ', ProviderFactory::driverCodes())
                );
            }

            $baseUrl = $options['base-url'] ?? (string) $current['base_url'];

            if (!str_starts_with(strtolower($baseUrl), 'https://')) {
                throw new RuntimeException('--base-url must use HTTPS.');
            }

            $repository->update($id, [
                'name'            => $options['name']  ?? (string) $current['name'],
                'driver'          => $driver,
                'base_url'        => rtrim($baseUrl, '/'),
                'model'           => $options['model'] ?? (string) $current['model'],
                'temperature'     => (float) ($options['temperature'] ?? $current['temperature']),
                'max_tokens'      => (int) ($options['max-tokens'] ?? $current['max_tokens']),
                'timeout_seconds' => (int) ($options['timeout'] ?? $current['timeout_seconds']),
                'max_retries'     => (int) ($options['retries'] ?? $current['max_retries']),
                'extra_headers'   => $options['headers'] ?? ($current['extra_headers'] ?? null),
                'is_active'       => isset($options['inactive']) ? 0 : (int) $current['is_active'],
                'priority'        => (int) ($options['priority'] ?? $current['priority']),
            ], $options['key'] ?? null);

            echo "\nProvider #" . $id . " updated"
               . (isset($options['key']) ? ' (API key replaced)' : '') . ".\n\n";
            break;

        case 'activate':
        case 'deactivate':
            $id = (int) ($options['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('--id is required.');
            }

            $repository->setActive($id, $command === 'activate');
            echo "\nProvider #" . $id . " " . $command . "d.\n\n";
            break;

        case 'default':
            $id = (int) ($options['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('--id is required.');
            }

            $repository->setDefault($id);
            echo "\nProvider #" . $id . " is now the default and will be tried first.\n\n";
            break;

        case 'delete':
            $id = (int) ($options['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('--id is required.');
            }

            $repository->delete($id);
            echo "\nProvider #" . $id . " deleted.\n\n";
            break;

        case 'test':
            $id     = (int) ($options['id'] ?? 0);
            $config = $id > 0 ? $repository->find($id) : ($repository->activeChain()[0] ?? null);

            if ($config === null) {
                throw new RuntimeException(
                    $id > 0
                        ? 'Provider #' . $id . ' not found.'
                        : 'No active provider with a stored key. Add one first.'
                );
            }

            echo "\nTesting \"" . $config->name . "\" (" . $config->driver . ", " . $config->model . ") ...\n";

            $dialect = new Dialect(
                1, 'general', 'General Haryanvi', 'सामान्य हरियाणवी', 'All Haryana',
                '', 'Use neutral Haryanvi. है becomes सै, हूँ becomes सूं.', true
            );
            $tone = new TonePreset(
                1, 'natural', 'Natural Conversational', 'सहज बातचीत', '',
                'Write the way people actually speak in everyday conversation.', true
            );

            $sample  = $options['text'] ?? 'मैं ठीक हूँ, तुम कैसे हो?';
            $request = new TranslationRequest($sample, 'hi', 'hi', $dialect, $tone, 'devanagari', true, 'api');
            $guard   = TextGuard::protect($sample);
            $prompt  = (new PromptBuilder())->build($request, $guard, []);

            $started  = microtime(true);
            $response = ProviderFactory::make($config)->complete($prompt['system'], $prompt['user']);
            $elapsed  = (int) round((microtime(true) - $started) * 1000);

            echo "\n  Source : " . $sample . "\n";
            echo "  Reply  : " . mb_substr($response->content, 0, 400) . "\n";
            echo "  Tokens : " . $response->tokensIn . " in / " . $response->tokensOut . " out\n";
            echo "  Time   : " . $elapsed . " ms (" . $response->attempts . " attempt(s))\n";
            echo "\nProvider is working.\n\n";
            break;

        default:
            echo "\nUsage: php bin/provider.php <list|add|update|activate|deactivate|default|test|delete> [options]\n";
            echo "Run \"php bin/provider.php add --help\" to see the available drivers.\n\n";
            exit(1);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFailed: " . $e->getMessage() . "\n\n");
    exit(1);
}
