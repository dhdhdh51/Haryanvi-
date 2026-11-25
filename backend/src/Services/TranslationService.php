<?php

declare(strict_types=1);

namespace Haryanvi\Services;

use Haryanvi\Core\ApiException;
use Haryanvi\Core\Logger;
use Haryanvi\Models\TranslationRequest;
use Haryanvi\Models\TranslationResult;
use Haryanvi\Providers\ProviderException;
use Haryanvi\Providers\ProviderFactory;
use Haryanvi\Repositories\DialectRepository;
use Haryanvi\Repositories\ProviderRepository;
use Haryanvi\Repositories\UsageRepository;

/**
 * The translation engine.
 *
 * Responsibilities, in order:
 *   1. Protect spans that must survive untouched (TextGuard).
 *   2. Gather dialect-specific reference vocabulary.
 *   3. Build the structured prompt (PromptBuilder).
 *   4. Walk the configured provider chain, retrying transient failures and
 *      falling back to the next provider on permanent ones.
 *   5. Parse the model's JSON reply defensively.
 *   6. Restore protected spans and verify nothing was lost.
 *   7. Record usage metadata — never the text itself.
 *
 * If no provider is configured it raises AI_PROVIDER_NOT_CONFIGURED. It never
 * invents, echoes or stubs a translation.
 */
final class TranslationService
{
    public function __construct(
        private readonly ProviderRepository $providers = new ProviderRepository(),
        private readonly DialectRepository $dialects = new DialectRepository(),
        private readonly UsageRepository $usage = new UsageRepository(),
        private readonly PromptBuilder $prompts = new PromptBuilder(),
    ) {
    }

    public function translate(TranslationRequest $request, ?int $userId): TranslationResult
    {
        $chain = $this->providers->activeChain();

        if ($chain === []) {
            $this->usage->record(
                $userId, null, 'not_configured', '/api/v1/translate', null,
                $request->charCount(), 0, 0, 0, 0,
                'AI_PROVIDER_NOT_CONFIGURED',
                'No active AI provider with stored credentials.',
                $request->channel
            );

            throw ApiException::providerNotConfigured();
        }

        $guard      = TextGuard::protect($request->sourceText);
        $vocabulary = $this->dialects->contextVocabulary(
            $request->dialect->id,
            $request->sourceText,
            SettingsService::getInt('vocab_context_limit', 40)
        );

        $prompt = $this->prompts->build($request, $guard, $vocabulary);

        $failures = [];

        foreach ($chain as $index => $providerConfig) {
            try {
                $provider = ProviderFactory::make($providerConfig);
                $response = $provider->complete($prompt['system'], $prompt['user']);
                $parsed   = $this->parseModelReply($response->content);

                $translated = $guard->restore($parsed['haryanvi']);
                $roman      = $parsed['roman'] === null ? null : $guard->restore($parsed['roman']);

                $this->verifyProtectedSpans($guard, $parsed['haryanvi']);

                // The server's own detector is deterministic and unit-tested,
                // so it stays authoritative. The model's opinion is recorded
                // only when it disagrees, which is useful for spotting
                // detector blind spots without affecting the response.
                if ($parsed['detected_language'] !== null
                    && $parsed['detected_language'] !== $request->detectedLang) {
                    Logger::debug('Model disagreed with server language detection', [
                        'server' => $request->detectedLang,
                        'model'  => $parsed['detected_language'],
                    ]);
                }

                $this->providers->recordSuccess($providerConfig->id);

                $this->usage->record(
                    $userId,
                    $providerConfig->id,
                    'success',
                    '/api/v1/translate',
                    $response->model,
                    $request->charCount(),
                    $response->tokensIn,
                    $response->tokensOut,
                    $response->latencyMs,
                    $response->attempts,
                    null,
                    null,
                    $request->channel
                );

                return new TranslationResult(
                    $translated,
                    $roman,
                    $request->detectedLang,
                    $providerConfig->id,
                    $providerConfig->name,
                    $response->model,
                    $response->tokensIn,
                    $response->tokensOut,
                    $response->latencyMs,
                    $response->attempts,
                    $failures,
                );
            } catch (ProviderException $e) {
                $failures[] = $providerConfig->slug;

                $this->providers->recordError($providerConfig->id, $e->getMessage());

                $this->usage->record(
                    $userId,
                    $providerConfig->id,
                    $e->errorCode() === 'TIMEOUT' ? 'timeout'
                        : ($e->errorCode() === 'PROVIDER_RATE_LIMITED' ? 'rate_limited' : 'error'),
                    '/api/v1/translate',
                    $providerConfig->model,
                    $request->charCount(),
                    0, 0, 0, 1,
                    $e->errorCode(),
                    $e->getMessage(),
                    $request->channel
                );

                Logger::error('AI provider failed', [
                    'provider'  => $providerConfig->slug,
                    'code'      => $e->errorCode(),
                    'http'      => $e->httpStatus(),
                    'remaining' => count($chain) - $index - 1,
                ]);

                // Try the next provider in the fallback chain, if any.
                continue;
            } catch (\RuntimeException $e) {
                // Most likely a key that cannot be decrypted after a key rotation.
                $failures[] = $providerConfig->slug;

                Logger::error('Provider configuration error', [
                    'provider' => $providerConfig->slug,
                    'message'  => $e->getMessage(),
                ]);

                $this->usage->record(
                    $userId, $providerConfig->id, 'error', '/api/v1/translate',
                    $providerConfig->model, $request->charCount(), 0, 0, 0, 1,
                    'PROVIDER_CONFIG_ERROR', $e->getMessage(), $request->channel
                );

                continue;
            }
        }

        throw ApiException::providerFailure(
            count($failures) === 1
                ? 'The translation service is temporarily unavailable. Please try again in a moment.'
                : 'All configured AI providers failed to respond. Please try again shortly.',
            ['providers_attempted' => count($failures)]
        );
    }

    /**
     * Read the model's JSON reply.
     *
     * Models occasionally wrap JSON in prose or code fences despite the
     * instruction, so this recovers the object rather than failing the whole
     * request. A reply with no usable JSON at all falls back to treating the
     * cleaned text as the translation — but only if it is not obviously
     * commentary.
     *
     * @return array{haryanvi:string,roman:?string,detected_language:?string,notes:?string}
     */
    private function parseModelReply(string $raw): array
    {
        $text = trim($raw);

        // Strip ```json fences.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }

        $decoded = json_decode($text, true);

        // Not clean JSON — try to extract the outermost object.
        if (!is_array($decoded)) {
            $start = strpos($text, '{');
            $end   = strrpos($text, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $candidate = substr($text, $start, $end - $start + 1);
                $decoded   = json_decode($candidate, true);
            }
        }

        if (is_array($decoded)) {
            $haryanvi = $decoded['haryanvi'] ?? $decoded['translation'] ?? $decoded['text'] ?? null;

            if (is_string($haryanvi) && trim($haryanvi) !== '') {
                $roman = $decoded['roman'] ?? $decoded['roman_text'] ?? null;
                $lang  = $decoded['detected_language'] ?? $decoded['detected_lang'] ?? null;
                $notes = $decoded['notes'] ?? null;

                return [
                    'haryanvi'          => trim($haryanvi),
                    'roman'             => is_string($roman) && trim($roman) !== '' ? trim($roman) : null,
                    'detected_language' => is_string($lang) && $lang !== '' ? $this->normaliseLang($lang) : null,
                    'notes'             => is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
                ];
            }

            // The reply was valid JSON but carried no translation. Falling
            // through to the plain-text path here would hand the user the raw
            // JSON envelope as if it were Haryanvi, so fail instead and let
            // the retry/fallback logic deal with it.
            throw ProviderException::emptyResponse();
        }

        // Last resort: a bare translation with no JSON wrapper.
        $plain = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($plain !== '' && mb_strlen($plain) <= 4000 && !$this->looksLikeRefusal($plain)) {
            Logger::warning('AI reply was not JSON; used the raw text', [
                'length' => mb_strlen($plain),
            ]);

            return [
                'haryanvi'          => $plain,
                'roman'             => null,
                'detected_language' => null,
                'notes'             => null,
            ];
        }

        throw ProviderException::malformedResponse('reply contained no usable translation');
    }

    /**
     * Detects a model declining to translate, so the fallback path does not
     * hand the user an apology as if it were Haryanvi.
     */
    private function looksLikeRefusal(string $text): bool
    {
        $lower = mb_strtolower($text);

        foreach ([
            "i'm sorry", 'i am sorry', 'i cannot', "i can't", 'i am unable',
            'as an ai', 'i apologize', 'i apologise',
        ] as $marker) {
            if (str_starts_with($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function normaliseLang(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return match (true) {
            str_contains($lower, 'hinglish')                              => 'hinglish',
            str_contains($lower, 'mixed')                                 => 'mixed',
            str_starts_with($lower, 'hi') || str_contains($lower, 'hindi') => 'hi',
            str_starts_with($lower, 'en') || str_contains($lower, 'engl')  => 'en',
            default                                                        => 'mixed',
        };
    }

    /**
     * Log — but do not fail — when a protected placeholder went missing.
     * The user still gets their translation; the incident is visible in the
     * logs so prompt quality can be tracked.
     */
    private function verifyProtectedSpans(TextGuard $guard, string $modelOutput): void
    {
        $missing = $guard->missingPlaceholders($modelOutput);

        if ($missing !== []) {
            Logger::warning('Protected spans missing from AI output', [
                'missing_count' => count($missing),
            ]);
        }
    }
}
