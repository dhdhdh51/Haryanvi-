<?php

declare(strict_types=1);

namespace Haryanvi\Admin;

/**
 * Template renderer for the dashboard.
 *
 * Every value a view prints goes through e() — the dashboard renders
 * user-submitted text (translations, feedback comments, display names), so
 * escaping is not optional.
 */
final class View
{
    public function __construct(
        private readonly string $viewPath,
        private readonly AdminSession $session,
    ) {
    }

    /**
     * Render a page inside the shared layout.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): void
    {
        // Page templates need the session too (for role-based controls), not
        // just the layout.
        $shared = $data + [
            'session'     => $this->session,
            'view'        => $this,
            'currentPage' => $data['currentPage'] ?? '',
            'pageTitle'   => $data['pageTitle'] ?? 'Dashboard',
        ];

        $content = $this->capture($template, $shared);

        echo $this->capture('layout', $shared + ['content' => $content]);
    }

    /**
     * Render a standalone page with no navigation (login, password change).
     *
     * @param array<string,mixed> $data
     */
    public function renderBare(string $template, array $data = []): void
    {
        echo $this->capture($template, $data + ['session' => $this->session, 'view' => $this]);
    }

    /** @param array<string,mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->viewPath . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    /* ----------------------------------------------------------------
     | Escaping and formatting helpers used by the templates
     * -------------------------------------------------------------- */

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function number(mixed $value): string
    {
        return number_format((float) ($value ?? 0));
    }

    /**
     * Compact large counts: 12.4k, 3.1M.
     */
    public static function compact(mixed $value): string
    {
        $number = (int) ($value ?? 0);

        return match (true) {
            $number >= 1_000_000 => round($number / 1_000_000, 1) . 'M',
            $number >= 1_000     => round($number / 1_000, 1) . 'k',
            default              => (string) $number,
        };
    }

    /**
     * Relative time for timestamps stored in UTC.
     */
    public static function ago(mixed $timestamp): string
    {
        if (!is_string($timestamp) || $timestamp === '') {
            return '—';
        }

        $time = strtotime($timestamp . ' UTC');

        if ($time === false) {
            return self::e($timestamp);
        }

        $seconds = time() - $time;

        return match (true) {
            $seconds < 60      => 'just now',
            $seconds < 3600    => (int) floor($seconds / 60) . 'm ago',
            $seconds < 86400   => (int) floor($seconds / 3600) . 'h ago',
            $seconds < 2592000 => (int) floor($seconds / 86400) . 'd ago',
            default            => gmdate('j M Y', $time),
        };
    }

    public static function datetime(mixed $timestamp): string
    {
        if (!is_string($timestamp) || $timestamp === '') {
            return '—';
        }

        $time = strtotime($timestamp . ' UTC');

        return $time === false ? self::e($timestamp) : gmdate('j M Y, H:i', $time) . ' UTC';
    }

    public static function truncate(mixed $value, int $length = 80): string
    {
        $text = (string) ($value ?? '');

        return self::e(mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text);
    }

    /**
     * Build a dashboard URL, preserving the current page where useful.
     *
     * @param array<string,string|int> $params
     */
    public static function url(array $params): string
    {
        return '?' . http_build_query($params);
    }
}
