<?php

declare(strict_types=1);

namespace Haryanvi\Providers;

/**
 * Driver for the Google Gemini generateContent API.
 *
 * Expected base_url: https://generativelanguage.googleapis.com
 */
final class GeminiProvider extends AbstractHttpProvider
{
    public function driverLabel(): string
    {
        return 'Google Gemini generateContent';
    }

    protected function endpoint(): string
    {
        // Gemini authenticates with a header (x-goog-api-key), so the key is
        // kept out of the URL and therefore out of any proxy access log.
        return sprintf(
            '%s/v1beta/models/%s:generateContent',
            $this->providerConfig->baseUrl,
            rawurlencode($this->providerConfig->model)
        );
    }

    /** @return list<string> */
    protected function authHeaders(): array
    {
        return ['x-goog-api-key: ' . $this->providerConfig->apiKey()];
    }

    /** @return array<string,mixed> */
    protected function buildPayload(string $systemPrompt, string $userPrompt): array
    {
        return [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'generationConfig' => [
                'temperature'      => $this->providerConfig->temperature,
                'maxOutputTokens'  => $this->providerConfig->maxTokens,
                'responseMimeType' => 'application/json',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{content:string,tokens_in:int,tokens_out:int}
     */
    protected function parseResponse(array $decoded): array
    {
        $candidates = $decoded['candidates'] ?? null;

        if (!is_array($candidates) || $candidates === []) {
            $error = self::dig($decoded, 'error', 'message');
            if (is_string($error)) {
                throw new ProviderException(
                    'The AI provider reported: ' . mb_substr($error, 0, 200),
                    'PROVIDER_REPORTED_ERROR',
                    false
                );
            }

            // A safety block returns no candidates but does include a reason.
            $blockReason = self::dig($decoded, 'promptFeedback', 'blockReason');
            if (is_string($blockReason)) {
                throw new ProviderException(
                    'The AI provider blocked this text (' . $blockReason . '). Try rephrasing it.',
                    'CONTENT_BLOCKED',
                    false
                );
            }

            throw ProviderException::malformedResponse('no "candidates" array present');
        }

        $parts = self::dig($candidates[0] ?? [], 'content', 'parts');

        if (!is_array($parts)) {
            throw ProviderException::malformedResponse('candidates[0].content.parts missing');
        }

        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if ($text === '') {
            throw ProviderException::malformedResponse('no text part in the first candidate');
        }

        return [
            'content'    => $text,
            'tokens_in'  => self::intOrZero(self::dig($decoded, 'usageMetadata', 'promptTokenCount')),
            'tokens_out' => self::intOrZero(self::dig($decoded, 'usageMetadata', 'candidatesTokenCount')),
        ];
    }
}
