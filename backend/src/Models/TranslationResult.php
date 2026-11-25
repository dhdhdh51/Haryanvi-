<?php

declare(strict_types=1);

namespace Haryanvi\Models;

/**
 * Outcome of a successful translation, including the provider metadata the
 * admin dashboard needs for usage accounting.
 */
final class TranslationResult
{
    public function __construct(
        public readonly string $translatedText,
        public readonly ?string $romanText,
        public readonly string $detectedLang,
        public readonly int $providerId,
        public readonly string $providerName,
        public readonly string $model,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly int $latencyMs,
        public readonly int $attempts,
        /** Slugs of providers that failed before this one succeeded. */
        public readonly array $fallbacksUsed = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'translated_text' => $this->translatedText,
            'roman_text'      => $this->romanText,
            'detected_lang'   => $this->detectedLang,
            'model'           => $this->model,
            'latency_ms'      => $this->latencyMs,
        ];
    }
}
