<?php

declare(strict_types=1);

namespace Haryanvi\Providers;

/**
 * Driver for the Anthropic Messages API.
 *
 * Expected base_url: https://api.anthropic.com
 */
final class AnthropicProvider extends AbstractHttpProvider
{
    private const API_VERSION = '2023-06-01';

    public function driverLabel(): string
    {
        return 'Anthropic Messages API';
    }

    protected function endpoint(): string
    {
        $base = $this->providerConfig->baseUrl;

        if (str_ends_with($base, '/messages')) {
            return $base;
        }

        return $base . '/v1/messages';
    }

    /** @return list<string> */
    protected function authHeaders(): array
    {
        return [
            'x-api-key: ' . $this->providerConfig->apiKey(),
            'anthropic-version: ' . self::API_VERSION,
        ];
    }

    /** @return array<string,mixed> */
    protected function buildPayload(string $systemPrompt, string $userPrompt): array
    {
        return [
            'model'       => $this->providerConfig->model,
            // Anthropic takes the system prompt as a dedicated field.
            'system'      => $systemPrompt,
            'messages'    => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $this->providerConfig->temperature,
            'max_tokens'  => $this->providerConfig->maxTokens,
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{content:string,tokens_in:int,tokens_out:int}
     */
    protected function parseResponse(array $decoded): array
    {
        $blocks = $decoded['content'] ?? null;

        if (!is_array($blocks) || $blocks === []) {
            $error = self::dig($decoded, 'error', 'message');
            if (is_string($error)) {
                throw new ProviderException(
                    'The AI provider reported: ' . mb_substr($error, 0, 200),
                    'PROVIDER_REPORTED_ERROR',
                    false
                );
            }

            throw ProviderException::malformedResponse('no "content" blocks present');
        }

        // Concatenate every text block; tool/thinking blocks are ignored.
        $text = '';
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        if ($text === '') {
            throw ProviderException::malformedResponse('no text block in the content array');
        }

        return [
            'content'    => $text,
            'tokens_in'  => self::intOrZero(self::dig($decoded, 'usage', 'input_tokens')),
            'tokens_out' => self::intOrZero(self::dig($decoded, 'usage', 'output_tokens')),
        ];
    }
}
