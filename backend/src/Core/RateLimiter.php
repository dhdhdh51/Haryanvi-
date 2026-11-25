<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * Fixed-window rate limiter backed by the `rate_limits` table.
 *
 * Server-side only — client-supplied counts are never trusted. Buckets are
 * keyed by a caller-chosen scope plus an identity (user id or IP) so the same
 * mechanism throttles authentication attempts and translation requests.
 */
final class RateLimiter
{
    /**
     * Register one hit and throw 429 once the limit for the window is passed.
     *
     * @param string $scope      logical bucket, e.g. 'translate.minute'
     * @param string $identity   user id, IP address, or email hash
     * @param int    $limit      maximum hits allowed per window
     * @param int    $windowSecs window length in seconds
     */
    public static function hit(string $scope, string $identity, int $limit, int $windowSecs): void
    {
        if ($limit <= 0 || $windowSecs <= 0) {
            return; // limiting disabled
        }

        $key         = self::bucketKey($scope, $identity);
        $windowStart = self::windowStart($windowSecs);

        // Atomic upsert: one statement, no read-modify-write race.
        Database::execute(
            'INSERT INTO rate_limits (bucket_key, window_start, hits)
             VALUES (:key, :window_start, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['key' => $key, 'window_start' => $windowStart]
        );

        $hits = (int) Database::fetchValue(
            'SELECT hits FROM rate_limits WHERE bucket_key = :key AND window_start = :window_start',
            ['key' => $key, 'window_start' => $windowStart]
        );

        if ($hits > $limit) {
            $retryAfter = self::secondsUntilWindowEnd($windowSecs);

            Logger::warning('Rate limit exceeded', [
                'scope' => $scope,
                'limit' => $limit,
                'hits'  => $hits,
            ]);

            throw ApiException::rateLimited(
                sprintf(
                    'Too many requests. You may make %d request%s per %s. Try again in %d second%s.',
                    $limit,
                    $limit === 1 ? '' : 's',
                    self::describeWindow($windowSecs),
                    $retryAfter,
                    $retryAfter === 1 ? '' : 's'
                ),
                $retryAfter
            );
        }
    }

    /**
     * Current hit count without registering a new hit.
     */
    public static function currentHits(string $scope, string $identity, int $windowSecs): int
    {
        return (int) Database::fetchValue(
            'SELECT hits FROM rate_limits WHERE bucket_key = :key AND window_start = :window_start',
            [
                'key'          => self::bucketKey($scope, $identity),
                'window_start' => self::windowStart($windowSecs),
            ]
        );
    }

    /**
     * Clear a bucket — used after a successful login so a user who merely
     * mistyped their password is not left throttled.
     */
    public static function clear(string $scope, string $identity, int $windowSecs): void
    {
        Database::execute(
            'DELETE FROM rate_limits WHERE bucket_key = :key AND window_start = :window_start',
            [
                'key'          => self::bucketKey($scope, $identity),
                'window_start' => self::windowStart($windowSecs),
            ]
        );
    }

    /**
     * Housekeeping for shared hosting without cron: drop windows older than
     * a day. Cheap because `window_start` is indexed.
     */
    public static function prune(): void
    {
        Database::execute(
            'DELETE FROM rate_limits WHERE window_start < (UTC_TIMESTAMP() - INTERVAL 1 DAY)'
        );
    }

    private static function bucketKey(string $scope, string $identity): string
    {
        // Hash the identity so raw IPs and emails are not stored in the table.
        return substr($scope, 0, 40) . ':' . hash('sha256', $identity);
    }

    private static function windowStart(int $windowSecs): string
    {
        $now = time();

        return gmdate('Y-m-d H:i:s', $now - ($now % $windowSecs));
    }

    private static function secondsUntilWindowEnd(int $windowSecs): int
    {
        $now = time();

        return max(1, $windowSecs - ($now % $windowSecs));
    }

    private static function describeWindow(int $windowSecs): string
    {
        return match (true) {
            $windowSecs <= 60   => 'minute',
            $windowSecs <= 3600 => 'hour',
            default             => 'day',
        };
    }
}
