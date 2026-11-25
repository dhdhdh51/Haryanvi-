<?php

declare(strict_types=1);

namespace Haryanvi\Providers;

use Haryanvi\Models\ProviderConfig;

/**
 * Contract every AI backend implements.
 *
 * Keeping the surface this small is what makes the architecture
 * provider-independent: the translation service only ever asks for a
 * completion and receives normalised text plus token counts.
 */
interface AiProvider
{
    /**
     * Run one chat/completion round trip.
     *
     * @throws ProviderException on transport, credential, quota or parsing failures
     */
    public function complete(string $systemPrompt, string $userPrompt): ProviderResponse;

    public function config(): ProviderConfig;

    /**
     * Human-readable driver label used in admin diagnostics.
     */
    public function driverLabel(): string;
}
