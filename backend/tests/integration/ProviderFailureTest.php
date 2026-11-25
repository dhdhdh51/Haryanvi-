<?php

declare(strict_types=1);

use Haryanvi\Tests\Assert;
use Haryanvi\Tests\HttpClient;

/**
 * AI provider failure handling, exercised against the mock provider.
 *
 * Covers the behaviours the project spec requires: retry on transient errors,
 * fall through to the next provider on permanent ones, a clear configuration
 * error when nothing is set up, and — critically — never returning a
 * fabricated translation when the provider fails.
 *
 * Requires, in addition to the variables ApiTest needs:
 *   HARYANVI_PROVIDER_CLI  command that runs bin/provider.php
 *                          e.g. "php -d curl.cainfo=/path/ca.crt /app/bin/provider.php"
 *   HARYANVI_MOCK_URL      https base URL of the mock provider (…/v1)
 *
 * Skipped when those are absent.
 */

$providerCli = (string) getenv('HARYANVI_PROVIDER_CLI');
$mockUrl     = (string) getenv('HARYANVI_MOCK_URL');
$baseUrl     = rtrim((string) getenv('HARYANVI_TEST_URL'), '/');

if ($providerCli === '' || $mockUrl === '') {
    echo "  (skipped: HARYANVI_PROVIDER_CLI / HARYANVI_MOCK_URL not set)\n";

    return;
}

Assert::startTest('Provider failure handling');

/** Run the project's own provider CLI. */
$cli = static function (string $arguments) use ($providerCli): string {
    $output = shell_exec($providerCli . ' ' . $arguments . ' 2>&1');

    return is_string($output) ? $output : '';
};

/** Set the primary provider's model, which selects the mock's behaviour. */
$setModel = static function (string $model, int $timeout = 10, int $retries = 1) use ($cli): void {
    $cli(sprintf('update --id=1 --model=%s --timeout=%d --retries=%d', escapeshellarg($model), $timeout, $retries));
};

/* ---- A throwaway account for these calls ---- */
$client   = new HttpClient($baseUrl);
$unique   = bin2hex(random_bytes(6));
$email    = 'pftest+' . $unique . '@example.com';
$password = 'Provider-Failure-' . $unique;

$registered = $client->post('/api/v1/auth/register', [
    'email'        => $email,
    'password'     => $password,
    'display_name' => 'Provider Failure Test',
]);
Assert::same(201, $registered->status, 'Test account created');
$client->withToken((string) $registered->data('token'));

$translate = static fn (string $text = 'मैं ठीक हूँ') => $client->post('/api/v1/translate', ['text' => $text]);

/* =====================================================================
 | Baseline: the happy path still works
 * =================================================================== */

$setModel('mock-ok');
$ok = $translate();
Assert::same(200, $ok->status, 'Baseline translation succeeds before failure injection');
Assert::true(trim((string) $ok->data('translated_text')) !== '', 'Baseline returns a non-empty translation');

/* =====================================================================
 | Permanent failures — no retry, surfaced as 502
 * =================================================================== */

$setModel('mock-401');
$unauthorized = $translate();
Assert::same(502, $unauthorized->status, 'Invalid provider credentials surface as 502');
Assert::same('AI_PROVIDER_FAILED', $unauthorized->errorCode(), 'Provider failure error code returned');
Assert::isNull($unauthorized->data('translated_text'), 'No translation text is invented on failure');
Assert::notContains('sk-', $unauthorized->errorMessage(), 'Provider key never leaks into the error message');

/* =====================================================================
 | Transient failures — retried, then surfaced
 * =================================================================== */

$setModel('mock-500', 10, 2);
$serverError = $translate();
Assert::same(502, $serverError->status, 'Provider 500 surfaces as 502 after retries');

$setModel('mock-429', 10, 1);
$rateLimited = $translate();
Assert::same(502, $rateLimited->status, 'Provider rate limiting surfaces as 502');

/* =====================================================================
 | Malformed and unusable responses
 * =================================================================== */

$setModel('mock-malformed');
$malformed = $translate();
Assert::same(502, $malformed->status, 'Non-JSON provider response surfaces as 502');
Assert::isNull($malformed->data('translated_text'), 'Malformed response yields no translation');

$setModel('mock-empty');
$empty = $translate();
Assert::same(502, $empty->status, 'Empty translation from the provider is rejected');

$setModel('mock-refusal');
$refusal = $translate();
Assert::same(502, $refusal->status, 'A model refusal is not passed off as a translation');
Assert::isNull($refusal->data('translated_text'), 'Refusal text is never returned as Haryanvi');

/* ---- A bare (non-JSON-wrapped) reply is still usable ---- */
$setModel('mock-plaintext');
$plaintext = $translate();
Assert::same(200, $plaintext->status, 'Plain-text reply is recovered rather than failing the request');
Assert::true(
    trim((string) $plaintext->data('translated_text')) !== '',
    'Recovered plain-text reply produces a translation'
);

/* =====================================================================
 | Timeout handling
 * =================================================================== */

$setModel('mock-slow', 2, 0);
$timeout = $translate();
Assert::same(502, $timeout->status, 'Provider timeout surfaces as 502');

/* =====================================================================
 | Fallback chain: primary fails permanently, secondary answers
 * =================================================================== */

$setModel('mock-401', 10, 0);

$added = $cli(sprintf(
    'add --name=%s --driver=openai_compatible --base-url=%s --model=mock-ok --key=%s --priority=200 --timeout=10 --retries=1',
    escapeshellarg('Mock Fallback'),
    escapeshellarg($mockUrl),
    escapeshellarg('sk-mock-fallback-key-0000')
));
Assert::contains('created', $added, 'Secondary provider registered for the fallback test');

$fellBack = $translate();
Assert::same(200, $fellBack->status, 'Request succeeds via the fallback provider: ' . $fellBack->describe());
Assert::true(
    trim((string) $fellBack->data('translated_text')) !== '',
    'Fallback provider produced a real translation'
);

/* =====================================================================
 | No provider configured at all
 * =================================================================== */

$cli('deactivate --id=1');
$cli('deactivate --id=2');

$notConfigured = $translate();
Assert::same(503, $notConfigured->status, 'All providers disabled returns 503');
Assert::same(
    'AI_PROVIDER_NOT_CONFIGURED',
    $notConfigured->errorCode(),
    'Unconfigured state reports AI_PROVIDER_NOT_CONFIGURED rather than a fake result'
);
Assert::contains(
    'admin',
    strtolower($notConfigured->errorMessage()),
    'Error message tells the operator where to configure a provider'
);
Assert::isNull($notConfigured->data('translated_text'), 'No translation returned when nothing is configured');

/* ---- Usage logging recorded the failures ---- */
$usage = $client->get('/api/v1/usage');
Assert::same(200, $usage->status, 'Usage endpoint still responds after provider failures');
// Successes so far: baseline, the recovered plain-text reply, and the
// fallback-provider call. Every injected failure must be excluded.
Assert::same(
    3,
    (int) $usage->data('usage.daily.used'),
    'Only successful translations counted against the quota'
);

/* ---- Restore the primary provider for any later test files ---- */
$cli('activate --id=1');
$setModel('mock-ok');
$cli('delete --id=2');

$restored = $translate();
Assert::same(200, $restored->status, 'Primary provider works again after being re-enabled');

/* ---- Clean up the throwaway account ---- */
$client->post('/api/v1/auth/delete-account', ['password' => $password, 'confirm' => 'DELETE']);
