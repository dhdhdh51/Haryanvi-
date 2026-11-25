<?php

declare(strict_types=1);

namespace Haryanvi\Services;

/**
 * Script and language detection for the source text.
 *
 * Deliberately lightweight and deterministic: it runs before the AI call to
 * choose the right prompt framing, and the model is still told to report what
 * it actually saw. Distinguishing Hinglish from English matters because the
 * two need very different handling — "kal milte hai" is Hindi written in
 * Latin script, not English.
 */
final class LanguageDetector
{
    /**
     * Function words that are strong evidence of romanised Hindi/Haryanvi.
     * Chosen to avoid English collisions (so no "me", "to", "is", "so", "a").
     *
     * @var list<string>
     */
    private const HINGLISH_MARKERS = [
        'hai', 'hain', 'hoon', 'hun', 'tha', 'thi', 'the', 'raha', 'rahi', 'rahe',
        'nahi', 'nahin', 'mat', 'kya', 'kyu', 'kyun', 'kaise', 'kahan', 'kab',
        'kitna', 'kitne', 'kaun', 'kuch', 'koi', 'sab', 'bahut', 'thoda', 'zyada',
        'mera', 'meri', 'tera', 'teri', 'tumhara', 'hamara', 'apna', 'mujhe',
        'tujhe', 'hume', 'unko', 'usko', 'iske', 'uske',
        'aap', 'tum', 'hum', 'yaar', 'bhai', 'didi', 'bhabhi', 'beta',
        'karo', 'karna', 'karta', 'karti', 'karte', 'kiya', 'karunga', 'karenge',
        'jao', 'jana', 'gaya', 'gayi', 'aaya', 'aayi', 'aana', 'milte', 'milna',
        'khana', 'peena', 'dekho', 'dekha', 'suno', 'bolo', 'bata', 'batao',
        'accha', 'achha', 'theek', 'thik', 'badhiya', 'matlab', 'abhi', 'phir',
        'kal', 'aaj', 'subah', 'shaam', 'raat', 'ghar', 'paisa', 'paise',
        'chalo', 'chal', 'ruko', 'jaldi', 'pata', 'baat', 'kaam', 'waqt',
        'ghana', 'bera', 'konya', 'chhora', 'chhori', 'sai',
    ];

    /** Short, very common English words used as counter-evidence. */
    private const ENGLISH_MARKERS = [
        'the', 'and', 'you', 'your', 'are', 'was', 'were', 'have', 'has', 'had',
        'will', 'would', 'can', 'could', 'should', 'this', 'that', 'these',
        'with', 'from', 'about', 'please', 'thanks', 'thank', 'today', 'tomorrow',
        'what', 'when', 'where', 'why', 'how', 'who', 'not', 'for', 'but',
        'going', 'doing', 'come', 'coming', 'send', 'call', 'meet', 'know',
        'good', 'fine', 'work', 'time', 'money', 'home', 'need', 'want',
    ];

    /**
     * @return 'hi'|'en'|'hinglish'|'mixed'
     */
    public static function detect(string $text): string
    {
        $devanagari = self::countDevanagari($text);
        $latin      = self::countLatinLetters($text);

        if ($devanagari === 0 && $latin === 0) {
            // Digits, punctuation or emoji only — nothing to infer from.
            return 'en';
        }

        $total = $devanagari + $latin;

        // Predominantly Devanagari.
        if ($devanagari > 0 && $latin / max(1, $total) < 0.15) {
            return 'hi';
        }

        // Predominantly Latin.
        if ($latin > 0 && $devanagari / max(1, $total) < 0.15) {
            return self::classifyLatin($text);
        }

        // Genuine script mixing, e.g. "मैं office जा रहा हूँ".
        return 'mixed';
    }

    /**
     * Is the Latin-script text romanised Hindi or actual English?
     *
     * @return 'en'|'hinglish'
     */
    private static function classifyLatin(string $text): string
    {
        $words = self::words($text);

        if ($words === []) {
            return 'en';
        }

        $hinglishHits = 0;
        $englishHits  = 0;

        foreach ($words as $word) {
            if (in_array($word, self::HINGLISH_MARKERS, true)) {
                $hinglishHits++;
            } elseif (in_array($word, self::ENGLISH_MARKERS, true)) {
                $englishHits++;
            }
        }

        if ($hinglishHits === 0) {
            return 'en';
        }

        // Hinglish wins on a tie: romanised Hindi borrows English words
        // constantly, while English text rarely contains Hindi function words.
        return $hinglishHits >= $englishHits ? 'hinglish' : 'en';
    }

    public static function isDevanagari(string $text): bool
    {
        return self::countDevanagari($text) > 0;
    }

    private static function countDevanagari(string $text): int
    {
        // U+0900–U+097F is the Devanagari block.
        return preg_match_all('/[\x{0900}-\x{097F}]/u', $text) ?: 0;
    }

    private static function countLatinLetters(string $text): int
    {
        return preg_match_all('/[A-Za-z]/u', $text) ?: 0;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $lower = mb_strtolower($text);
        $parts = preg_split('/[^a-z]+/', $lower, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    /**
     * Label for prompts and history rows.
     */
    public static function label(string $code): string
    {
        return match ($code) {
            'hi'       => 'Hindi (Devanagari script)',
            'en'       => 'English',
            'hinglish' => 'Hinglish (Hindi written in Latin/Roman script)',
            'mixed'    => 'Mixed Hindi and English',
            default    => 'Unknown',
        };
    }
}
