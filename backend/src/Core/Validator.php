<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * Request validator.
 *
 * Collects every field error before throwing, so the client receives one
 * complete 422 rather than discovering problems one at a time.
 *
 *   $v = new Validator($request->body());
 *   $email = $v->email('email');
 *   $pass  = $v->string('password', min: 8, max: 128);
 *   $v->assert();
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /* ----------------------------------------------------------------
     | Scalars
     * -------------------------------------------------------------- */

    public function string(
        string $field,
        bool $required = true,
        int $min = 0,
        int $max = 255,
        string $default = ''
    ): string {
        $raw = $this->data[$field] ?? null;

        if ($raw === null || $raw === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }

            return $default;
        }

        if (!is_scalar($raw)) {
            $this->errors[$field] = 'Must be a text value.';

            return $default;
        }

        // Normalise line endings and strip control characters other than
        // tab/newline, which otherwise corrupt stored text and prompts.
        $value = (string) $raw;
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = trim($value);

        $length = mb_strlen($value);

        if ($length < $min) {
            $this->errors[$field] = sprintf('Must be at least %d characters.', $min);
        } elseif ($length > $max) {
            $this->errors[$field] = sprintf('Must not exceed %d characters.', $max);
        }

        return $value;
    }

    public function email(string $field, bool $required = true): string
    {
        $value = $this->string($field, $required, 3, 190);

        if ($value === '') {
            return '';
        }

        $normalised = strtolower($value);

        if (filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = 'Enter a valid email address.';

            return '';
        }

        return $normalised;
    }

    /**
     * Password strength check. Deliberately length-first (NIST guidance)
     * rather than demanding a specific character-class cocktail.
     */
    public function password(string $field, int $min = 8): string
    {
        $raw = $this->data[$field] ?? null;

        if (!is_string($raw) || $raw === '') {
            $this->errors[$field] = 'This field is required.';

            return '';
        }

        if (mb_strlen($raw) < $min) {
            $this->errors[$field] = sprintf('Must be at least %d characters.', $min);
        } elseif (mb_strlen($raw) > 200) {
            $this->errors[$field] = 'Must not exceed 200 characters.';
        } elseif (preg_match('/^\d+$/', $raw) === 1) {
            $this->errors[$field] = 'Choose a password that is not only digits.';
        }

        return $raw;
    }

    public function int(string $field, bool $required = true, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, int $default = 0): int
    {
        $raw = $this->data[$field] ?? null;

        if ($raw === null || $raw === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }

            return $default;
        }

        if (!is_numeric($raw)) {
            $this->errors[$field] = 'Must be a number.';

            return $default;
        }

        $value = (int) $raw;

        if ($value < $min || $value > $max) {
            $this->errors[$field] = sprintf('Must be between %d and %d.', $min, $max);
        }

        return $value;
    }

    public function float(string $field, bool $required = true, float $min = -PHP_FLOAT_MAX, float $max = PHP_FLOAT_MAX, float $default = 0.0): float
    {
        $raw = $this->data[$field] ?? null;

        if ($raw === null || $raw === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }

            return $default;
        }

        if (!is_numeric($raw)) {
            $this->errors[$field] = 'Must be a number.';

            return $default;
        }

        $value = (float) $raw;

        if ($value < $min || $value > $max) {
            $this->errors[$field] = sprintf('Must be between %s and %s.', $min, $max);
        }

        return $value;
    }

    public function bool(string $field, bool $default = false): bool
    {
        $raw = $this->data[$field] ?? null;

        if ($raw === null) {
            return $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    /**
     * @param list<string> $allowed
     */
    public function enum(string $field, array $allowed, bool $required = true, string $default = ''): string
    {
        $raw = $this->data[$field] ?? null;

        if ($raw === null || $raw === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }

            return $default;
        }

        if (!is_scalar($raw)) {
            $this->errors[$field] = 'Invalid value.';

            return $default;
        }

        $value = strtolower(trim((string) $raw));

        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = 'Must be one of: ' . implode(', ', $allowed) . '.';

            return $default;
        }

        return $value;
    }

    public function url(string $field, bool $required = true, bool $requireHttps = true): string
    {
        $value = $this->string($field, $required, 0, 255);

        if ($value === '') {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->errors[$field] = 'Enter a valid URL.';

            return '';
        }

        if ($requireHttps && !str_starts_with(strtolower($value), 'https://')) {
            $this->errors[$field] = 'URL must use HTTPS.';

            return '';
        }

        return $value;
    }

    /**
     * Validates a JSON string field and returns the decoded array.
     *
     * @return array<string,mixed>
     */
    public function jsonObject(string $field, bool $required = false): array
    {
        $raw = $this->data[$field] ?? null;

        if ($raw === null || $raw === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }

            return [];
        }

        if (is_array($raw)) {
            /** @var array<string,mixed> $raw */
            return $raw;
        }

        if (!is_string($raw)) {
            $this->errors[$field] = 'Must be a JSON object.';

            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $this->errors[$field] = 'Must be valid JSON.';

            return [];
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /* ----------------------------------------------------------------
     | Manual errors + finalisation
     * -------------------------------------------------------------- */

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Throw a single 422 containing every collected error.
     */
    public function assert(): void
    {
        if ($this->errors !== []) {
            throw ApiException::validation('Please correct the highlighted fields.', $this->errors);
        }
    }
}
