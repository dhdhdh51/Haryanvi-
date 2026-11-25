<?php

declare(strict_types=1);

use Haryanvi\Models\ProviderConfig;
use Haryanvi\Providers\AnthropicProvider;
use Haryanvi\Providers\GeminiProvider;
use Haryanvi\Providers\OpenAiCompatibleProvider;
use Haryanvi\Providers\ProviderException;
use Haryanvi\Providers\ProviderFactory;
use Haryanvi\Tests\Assert;

/**
 * Each driver's response parsing and endpoint construction, exercised without
 * any network access by invoking the protected methods directly.
 */

Assert::startTest('Provider parsing');

$makeConfig = static function (string $driver, string $baseUrl, string $model): ProviderConfig {
    return new ProviderConfig(
        1, 'Test provider', 'test', $driver, $baseUrl, $model,
        null, 0.3, 1024, 30, 2, [], 100, true
    );
};

/** Reach a protected method for testing without changing production visibility. */
$call = static function (object $object, string $method, mixed ...$args): mixed {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object, ...$args);
};

/* ================= OpenAI-compatible ================= */

$openAi = new OpenAiCompatibleProvider($makeConfig('openai_compatible', 'https://api.example.com/v1', 'gpt-4o-mini'));

Assert::same(
    'https://api.example.com/v1/chat/completions',
    $call($openAi, 'endpoint'),
    'OpenAI endpoint appended to a /v1 base URL'
);

$fullPath = new OpenAiCompatibleProvider(
    $makeConfig('openai_compatible', 'https://api.example.com/v1/chat/completions', 'm')
);
Assert::same(
    'https://api.example.com/v1/chat/completions',
    $call($fullPath, 'endpoint'),
    'A full endpoint URL is not doubled up'
);

$parsed = $call($openAi, 'parseResponse', [
    'choices' => [['message' => ['content' => '{"haryanvi":"के हाल सै"}']]],
    'usage'   => ['prompt_tokens' => 120, 'completion_tokens' => 18],
]);
Assert::contains('के हाल सै', $parsed['content'], 'OpenAI content extracted');
Assert::same(120, $parsed['tokens_in'], 'OpenAI prompt tokens read');
Assert::same(18, $parsed['tokens_out'], 'OpenAI completion tokens read');

$payload = $call($openAi, 'buildPayload', 'SYSTEM', 'USER');
Assert::same('system', $payload['messages'][0]['role'], 'System message sent first');
Assert::same('USER', $payload['messages'][1]['content'], 'User message carried through');
Assert::same(0.3, $payload['temperature'], 'Configured temperature applied');
Assert::same(1024, $payload['max_tokens'], 'Configured token ceiling applied');

Assert::throws(
    static fn (): mixed => $call($openAi, 'parseResponse', ['choices' => []]),
    'Empty choices array is a parse failure',
);
Assert::throws(
    static fn (): mixed => $call($openAi, 'parseResponse', ['error' => ['message' => 'invalid model']]),
    'HTTP-200 error envelope surfaces as a provider error',
);

/* ================= Anthropic ================= */

$anthropic = new AnthropicProvider($makeConfig('anthropic', 'https://api.anthropic.com', 'claude-sonnet-4-5'));

Assert::same(
    'https://api.anthropic.com/v1/messages',
    $call($anthropic, 'endpoint'),
    'Anthropic messages endpoint built'
);

$parsed = $call($anthropic, 'parseResponse', [
    'content' => [
        ['type' => 'thinking', 'thinking' => 'ignore me'],
        ['type' => 'text', 'text' => '{"haryanvi":"मैं ठीक सूं"}'],
    ],
    'usage'   => ['input_tokens' => 90, 'output_tokens' => 12],
]);
Assert::contains('मैं ठीक सूं', $parsed['content'], 'Anthropic text block extracted');
Assert::notContains('ignore me', $parsed['content'], 'Non-text blocks skipped');
Assert::same(90, $parsed['tokens_in'], 'Anthropic input tokens read');

$payload = $call($anthropic, 'buildPayload', 'SYSTEM', 'USER');
Assert::same('SYSTEM', $payload['system'], 'Anthropic system prompt uses the dedicated field');
Assert::same(1, count($payload['messages']), 'Only the user turn is sent in messages');

Assert::throws(
    static fn (): mixed => $call($anthropic, 'parseResponse', ['content' => []]),
    'Anthropic empty content is a parse failure',
);

/* ================= Gemini ================= */

$gemini = new GeminiProvider($makeConfig('gemini', 'https://generativelanguage.googleapis.com', 'gemini-2.0-flash'));

Assert::same(
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
    $call($gemini, 'endpoint'),
    'Gemini endpoint includes the model name'
);
Assert::notContains('key=', $call($gemini, 'endpoint'), 'Gemini API key kept out of the URL');

$parsed = $call($gemini, 'parseResponse', [
    'candidates'    => [['content' => ['parts' => [['text' => '{"haryanvi":"घणा बढ़िया"}']]]]],
    'usageMetadata' => ['promptTokenCount' => 75, 'candidatesTokenCount' => 9],
]);
Assert::contains('घणा बढ़िया', $parsed['content'], 'Gemini candidate text extracted');
Assert::same(75, $parsed['tokens_in'], 'Gemini prompt tokens read');

$payload = $call($gemini, 'buildPayload', 'SYSTEM', 'USER');
Assert::same('SYSTEM', $payload['systemInstruction']['parts'][0]['text'], 'Gemini system instruction set');

Assert::throws(
    static fn (): mixed => $call($gemini, 'parseResponse', [
        'promptFeedback' => ['blockReason' => 'SAFETY'],
    ]),
    'Gemini safety block reported as a provider error',
);

/* ================= Error classification ================= */

Assert::false(
    ProviderException::fromHttpStatus(401, '{}')->isRetryable(),
    'HTTP 401 is permanent — fall through to the next provider'
);
Assert::false(
    ProviderException::fromHttpStatus(404, '{}')->isRetryable(),
    'HTTP 404 is permanent'
);
Assert::true(
    ProviderException::fromHttpStatus(429, '{}')->isRetryable(),
    'HTTP 429 is retryable'
);
Assert::true(
    ProviderException::fromHttpStatus(503, '{}')->isRetryable(),
    'HTTP 503 is retryable'
);
Assert::same(
    'INVALID_CREDENTIALS',
    ProviderException::fromHttpStatus(403, '{}')->errorCode(),
    'HTTP 403 maps to an actionable credential error'
);
Assert::contains(
    'API key',
    ProviderException::fromHttpStatus(401, '{}')->getMessage(),
    'Credential error message points the operator at the key'
);

/* ================= Factory ================= */

Assert::true(ProviderFactory::isValidDriver('openai_compatible'), 'openai_compatible is a known driver');
Assert::true(ProviderFactory::isValidDriver('anthropic'), 'anthropic is a known driver');
Assert::true(ProviderFactory::isValidDriver('gemini'), 'gemini is a known driver');
Assert::false(ProviderFactory::isValidDriver('made_up'), 'Unknown driver rejected');
Assert::same(3, count(ProviderFactory::driverCodes()), 'Three drivers registered');

Assert::true(
    ProviderFactory::make($makeConfig('anthropic', 'https://x', 'm')) instanceof AnthropicProvider,
    'Factory builds the Anthropic driver'
);
Assert::throws(
    static fn (): mixed => ProviderFactory::make($makeConfig('nope', 'https://x', 'm')),
    'Factory rejects an unknown driver'
);

/* ================= Secret hygiene ================= */

$withKey = new ProviderConfig(
    1, 'Test', 'test', 'openai_compatible', 'https://x', 'm',
    null, 0.3, 1024, 30, 2, [], 100, false
);
Assert::false($withKey->hasApiKey(), 'Provider without a stored key reports so');
Assert::notContains('api_key', json_encode($withKey->toArray()) ?: '', 'Serialised provider omits the key field');
Assert::throws(
    static fn (): string => $withKey->apiKey(),
    'Reading a missing API key throws rather than returning empty'
);
