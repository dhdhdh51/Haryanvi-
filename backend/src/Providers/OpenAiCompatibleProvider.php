<?php

declare(strict_types=1);

namespace Haryanvi\Providers;

/**
 * Driver for any service exposing the OpenAI chat-completions shape.
 *
 * Works with OpenAI, Azure OpenAI gateways, Groq, Together, OpenRouter,
 * DeepSeek, Mistral and local servers such as Ollama's OpenAI bridge or
 * vLLM — the admin only changes `base_url` and `model`.
 *
 * Expected base_url: https://api.openai.com/v1
 */
final class OpenAiCompatibleProvider extends AbstractHttpProvider
{
    public function driverLabel(): string
    {
        return 'OpenAI-compatible chat completions';
    }

    protected function endpoint(): string
    {
        $base = $this->providerConfig->baseUrl;

        // Tolerate admins pasting either ".../v1" or the full endpoint.
        if (str_ends_with($base, '/chat/completions')) {
            return $base;
        }

        return $base . '/chat/completions';
    }

    /** @return list<string> */
    protected function authHeaders(): array
    {
        return ['Authorization: Bearer ' . $this->providerConfig->apiKey()];
    }

    /** @return array<string,mixed> */
    protected function buildPayload(string $systemPrompt, string $userPrompt): array
    {
        return [
            'model'       => $this->providerConfig->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'temperature' => $this->providerConfig->temperature,
            'max_tokens'  => $this->providerConfig->maxTokens,
            // Asking for a JSON object is a hint; TranslationService still
            // parses defensively because not every compatible server honours it.
            'response_format' => ['type' => 'json_object'],
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{content:string,tokens_in:int,tokens_out:int}
     */
    protected function parseResponse(array $decoded): array
    {
        $choices = $decoded['choices'] ?? null;

        if (!is_array($choices) || $choices === []) {
            // Some gateways return {"error": {...}} with HTTP 200.
            $error = self::dig($decoded, 'error', 'message');
            if (is_string($error)) {
                throw new ProviderException(
                    'The AI provider reported: ' . mb_substr($error, 0, 200),
                    'PROVIDER_REPORTED_ERROR',
                    false
                );
            }

            throw ProviderException::malformedResponse('no "choices" array present');
        }

        $first   = $choices[0] ?? [];
        $content = is_array($first) ? self::dig($first, 'message', 'content') : null;

        if (!is_string($content)) {
            throw ProviderException::malformedResponse('choices[0].message.content was not a string');
        }

        return [
            'content'    => $content,
            'tokens_in'  => self::intOrZero(self::dig($decoded, 'usage', 'prompt_tokens')),
            'tokens_out' => self::intOrZero(self::dig($decoded, 'usage', 'completion_tokens')),
        ];
    }
}
