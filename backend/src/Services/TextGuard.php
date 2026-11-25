<?php

declare(strict_types=1);

namespace Haryanvi\Services;

/**
 * Protects spans that must survive translation byte-for-byte.
 *
 * The spec requires URLs, numbers, dates, emoji and technical terms to come
 * through unchanged. Two mechanisms are used, chosen per category:
 *
 *   1. Masking — for opaque tokens where a single altered character breaks
 *      them and translation adds nothing: URLs, emails, @handles, #hashtags,
 *      phone numbers and long digit strings. These are swapped for `{{n}}`
 *      placeholders before the model sees them and restored afterwards, so
 *      they cannot be "translated" at all.
 *
 *   2. Instruction + verification — for things the model must keep in place
 *      but still reason about (plain numbers, emoji, proper nouns). These are
 *      listed in the prompt and checked in the output; a mismatch is logged
 *      rather than hidden.
 */
final class TextGuard
{
    /**
     * Ordered most-specific first so a URL is not partly eaten by the
     * phone-number pattern.
     *
     * @var array<string,string>
     */
    private const PATTERNS = [
        // http(s) URLs and bare www. hosts
        'url'      => '#\b(?:https?://|www\.)[^\s<>"\'\x{0900}-\x{097F}]+#u',
        // email addresses
        'email'    => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/u',
        // @mentions
        'mention'  => '/(?<![\w@])@[A-Za-z0-9._]{2,30}\b/u',
        // #hashtags (Latin or Devanagari)
        'hashtag'  => '/(?<![\w#])#[\w\x{0900}-\x{097F}]{2,40}/u',
        // phone numbers: +91 98765 43210, 98765-43210, 9876543210
        'phone'    => '/(?<!\d)(?:\+\d{1,3}[\s\-]?)?(?:\d[\s\-]?){9,14}\d(?!\d)/u',
        // standalone runs of 5+ digits (OTPs, order ids, PINs)
        'longnum'  => '/(?<![\d\w])\d{5,}(?![\d\w])/u',
    ];

    /**
     * @param array<int,string> $map placeholder index => original text
     */
    private function __construct(
        public readonly string $maskedText,
        public readonly array $map,
        /** @var list<string> */
        public readonly array $emoji,
        /** @var list<string> */
        public readonly array $numbers,
    ) {
    }

    public static function protect(string $text): self
    {
        $map     = [];
        $counter = 0;
        $masked  = $text;

        foreach (self::PATTERNS as $pattern) {
            $masked = preg_replace_callback(
                $pattern,
                static function (array $m) use (&$map, &$counter): string {
                    // Keep whitespace the phone pattern may have swallowed.
                    $value   = $m[0];
                    $trimmed = rtrim($value);
                    $tail    = substr($value, strlen($trimmed));

                    $counter++;
                    $map[$counter] = $trimmed;

                    return '{{' . $counter . '}}' . $tail;
                },
                $masked
            ) ?? $masked;
        }

        return new self($masked, $map, self::findEmoji($text), self::findNumbers($masked));
    }

    /**
     * Put the original spans back. Missing placeholders are reported by
     * `verify()` rather than silently swallowed.
     */
    public function restore(string $translated): string
    {
        $result = $translated;

        foreach ($this->map as $index => $original) {
            $result = str_replace(
                ['{{' . $index . '}}', '{{ ' . $index . ' }}', '{ {' . $index . '} }'],
                $original,
                $result
            );
        }

        return $result;
    }

    /**
     * Placeholders the model dropped or mangled.
     *
     * @return list<int>
     */
    public function missingPlaceholders(string $translated): array
    {
        $missing = [];

        foreach (array_keys($this->map) as $index) {
            if (!str_contains($translated, '{{' . $index . '}}')
                && !str_contains($translated, '{{ ' . $index . ' }}')) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    public function hasProtectedSpans(): bool
    {
        return $this->map !== [] || $this->emoji !== [] || $this->numbers !== [];
    }

    /**
     * Human-readable constraints appended to the user prompt.
     */
    public function promptConstraints(): string
    {
        $lines = [];

        if ($this->map !== []) {
            $lines[] = 'Placeholders such as ' . implode(', ', array_map(
                static fn (int $i): string => '{{' . $i . '}}',
                array_keys($this->map)
            )) . ' stand in for links, emails, handles or numbers. Copy each placeholder into '
               . 'the translation exactly as written, in a natural position. Never translate, '
               . 'renumber, reword or drop one.';
        }

        if ($this->numbers !== []) {
            $lines[] = 'Keep these numbers exactly as they appear: '
                     . implode(', ', array_slice($this->numbers, 0, 20)) . '.';
        }

        if ($this->emoji !== []) {
            $lines[] = 'Keep these emoji, in the same order: '
                     . implode(' ', array_slice($this->emoji, 0, 20)) . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * Emoji and pictographic symbols present in the text.
     *
     * @return list<string>
     */
    private static function findEmoji(string $text): array
    {
        $pattern = '/[\x{1F300}-\x{1FAFF}\x{1F000}-\x{1F2FF}\x{2600}-\x{27BF}'
                 . '\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{2764}]/u';

        if (preg_match_all($pattern, $text, $matches) === false) {
            return [];
        }

        $found = $matches[0] ?? [];

        // Drop variation selectors, which carry no standalone meaning.
        $found = array_values(array_filter($found, static fn (string $c): bool => $c !== "\u{FE0F}"));

        return array_values(array_unique($found));
    }

    /**
     * Short numbers left inline after masking (prices, counts, times).
     *
     * @return list<string>
     */
    private static function findNumbers(string $maskedText): array
    {
        // Ignore the digits inside our own {{n}} placeholders.
        $withoutPlaceholders = preg_replace('/\{\{\d+\}\}/', ' ', $maskedText) ?? $maskedText;

        if (preg_match_all('/\d+(?:[.:,]\d+)*/u', $withoutPlaceholders, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[0] ?? []));
    }
}
