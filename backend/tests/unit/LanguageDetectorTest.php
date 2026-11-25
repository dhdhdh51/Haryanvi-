<?php

declare(strict_types=1);

use Haryanvi\Services\LanguageDetector;
use Haryanvi\Tests\Assert;

/**
 * Distinguishing Hinglish from English is the detector's whole reason for
 * existing: "kal milte hai" needs Hindi handling, "see you tomorrow" needs
 * English handling, and both are plain Latin text.
 */

Assert::startTest('LanguageDetector');

/* ---- Devanagari Hindi ---- */
Assert::same('hi', LanguageDetector::detect('मैं ठीक हूँ'), 'Devanagari text detected as Hindi');
Assert::same('hi', LanguageDetector::detect('क्या कर रहे हो भाई'), 'Devanagari question detected as Hindi');

/* ---- English ---- */
Assert::same('en', LanguageDetector::detect('What are you doing today?'), 'Plain English detected as English');
Assert::same('en', LanguageDetector::detect('Please send the report by tomorrow'), 'English sentence detected as English');
Assert::same('en', LanguageDetector::detect('Thanks for your help'), 'Short English detected as English');

/* ---- Hinglish (romanised Hindi) ---- */
Assert::same('hinglish', LanguageDetector::detect('kya kar raha hai bhai'), 'Romanised Hindi detected as Hinglish');
Assert::same('hinglish', LanguageDetector::detect('mujhe nahi pata yaar'), 'Hinglish with markers detected');
Assert::same('hinglish', LanguageDetector::detect('kal milte hai'), 'Short Hinglish detected');

/* ---- Mixed script ---- */
Assert::same('mixed', LanguageDetector::detect('मैं office जा रहा हूँ अभी'), 'Script mixing detected as mixed');

/* ---- Degenerate input ---- */
Assert::same('en', LanguageDetector::detect('12345'), 'Digits only fall back to English');
Assert::same('en', LanguageDetector::detect('!!!'), 'Punctuation only falls back to English');

/* ---- Helpers ---- */
Assert::true(LanguageDetector::isDevanagari('नमस्ते'), 'isDevanagari true for Devanagari');
Assert::false(LanguageDetector::isDevanagari('hello'), 'isDevanagari false for Latin');
Assert::contains('Hinglish', LanguageDetector::label('hinglish'), 'label() describes Hinglish');
