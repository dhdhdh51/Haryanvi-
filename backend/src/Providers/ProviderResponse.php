<?php

declare(strict_types=1);

namespace Haryanvi\Providers;

/**
 * Normalised reply from any AI provider, so the translation service does not
 * care which vendor answered.
 */
final class ProviderResponse
{
    public function __construct(
        public readonly string $content,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly int $latencyMs,
        public readonly int $attempts,
        public readonly string $model,
    ) {
    }
}
