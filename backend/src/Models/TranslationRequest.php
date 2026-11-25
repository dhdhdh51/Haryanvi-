<?php

declare(strict_types=1);

namespace Haryanvi\Models;

/**
 * A validated, fully-resolved translation job.
 *
 * By the time this object exists the dialect and tone have been looked up and
 * the source language has been detected, so the prompt builder and providers
 * never have to guess or re-parse client input.
 */
final class TranslationRequest
{
    public function __construct(
        public readonly string $sourceText,
        /** 'auto' | 'hi' | 'en' — what the client asked for */
        public readonly string $requestedSourceLang,
        /** 'hi' | 'en' | 'hinglish' | 'mixed' — what the detector concluded */
        public readonly string $detectedLang,
        public readonly Dialect $dialect,
        public readonly TonePreset $tone,
        /** 'devanagari' | 'roman' */
        public readonly string $outputScript,
        public readonly bool $includeRoman,
        /** 'app' | 'keyboard' | 'floating' | 'share' | 'api' */
        public readonly string $channel,
    ) {
    }

    public function charCount(): int
    {
        return mb_strlen($this->sourceText);
    }
}
