<?php

declare(strict_types=1);

namespace Haryanvi\Providers;

use Haryanvi\Models\ProviderConfig;

/**
 * Maps the `driver` column to a concrete implementation.
 *
 * Adding a new vendor means adding one class and one case here — no changes
 * to the translation service, controllers or database schema.
 */
final class ProviderFactory
{
    /** @var array<string,string> driver => human label */
    public const DRIVERS = [
        'openai_compatible' => 'OpenAI-compatible (OpenAI, Groq, OpenRouter, Together, Ollama, vLLM …)',
        'anthropic'         => 'Anthropic (Claude)',
        'gemini'            => 'Google Gemini',
    ];

    /** Sensible starting points shown in the admin "add provider" form. */
    public const DRIVER_DEFAULTS = [
        'openai_compatible' => ['base_url' => 'https://api.openai.com/v1',                  'model' => 'gpt-4o-mini'],
        'anthropic'         => ['base_url' => 'https://api.anthropic.com',                  'model' => 'claude-sonnet-4-5'],
        'gemini'            => ['base_url' => 'https://generativelanguage.googleapis.com',  'model' => 'gemini-2.0-flash'],
    ];

    public static function make(ProviderConfig $config): AiProvider
    {
        return match ($config->driver) {
            'openai_compatible' => new OpenAiCompatibleProvider($config),
            'anthropic'         => new AnthropicProvider($config),
            'gemini'            => new GeminiProvider($config),
            default             => throw new ProviderException(
                sprintf('Unknown provider driver "%s".', $config->driver),
                'UNKNOWN_DRIVER',
                false
            ),
        };
    }

    public static function isValidDriver(string $driver): bool
    {
        return array_key_exists($driver, self::DRIVERS);
    }

    /** @return list<string> */
    public static function driverCodes(): array
    {
        return array_keys(self::DRIVERS);
    }
}
