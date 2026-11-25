<?php

declare(strict_types=1);

namespace Haryanvi\Repositories;

use Haryanvi\Core\Database;

/**
 * Usage accounting and AI observability.
 *
 * `ai_usage_logs` is the audit trail (no user text, only metadata and sizes);
 * `usage_daily` is the pre-aggregated counter that quota checks read, so
 * enforcing a limit costs a single indexed row lookup.
 */
final class UsageRepository
{
    /**
     * Record one AI call and, on success, advance the user's daily counters.
     */
    public function record(
        ?int $userId,
        ?int $providerId,
        string $status,
        string $endpoint,
        ?string $model,
        int $requestChars,
        int $tokensIn,
        int $tokensOut,
        int $latencyMs,
        int $attempts,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?string $channel = null,
    ): void {
        Database::transaction(static function () use (
            $userId, $providerId, $status, $endpoint, $model, $requestChars,
            $tokensIn, $tokensOut, $latencyMs, $attempts, $errorCode, $errorMessage, $channel
        ): void {
            Database::execute(
                'INSERT INTO ai_usage_logs
                    (user_id, provider_id, endpoint, status, model, request_chars,
                     tokens_in, tokens_out, latency_ms, attempt_count,
                     error_code, error_message, source_channel)
                 VALUES
                    (:user_id, :provider_id, :endpoint, :status, :model, :request_chars,
                     :tokens_in, :tokens_out, :latency_ms, :attempts,
                     :error_code, :error_message, :channel)',
                [
                    'user_id'       => $userId,
                    'provider_id'   => $providerId,
                    'endpoint'      => $endpoint,
                    'status'        => $status,
                    'model'         => $model,
                    'request_chars' => $requestChars,
                    'tokens_in'     => $tokensIn,
                    'tokens_out'    => $tokensOut,
                    'latency_ms'    => $latencyMs,
                    'attempts'      => $attempts,
                    'error_code'    => $errorCode,
                    'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 500),
                    'channel'       => $channel,
                ]
            );

            if ($userId === null) {
                return;
            }

            $isSuccess = $status === 'success';

            Database::execute(
                'INSERT INTO usage_daily
                    (user_id, usage_date, translations, failed, chars, tokens_in, tokens_out)
                 VALUES
                    (:user_id, UTC_DATE(), :translations, :failed, :chars, :tokens_in, :tokens_out)
                 ON DUPLICATE KEY UPDATE
                    translations = translations + VALUES(translations),
                    failed       = failed + VALUES(failed),
                    chars        = chars + VALUES(chars),
                    tokens_in    = tokens_in + VALUES(tokens_in),
                    tokens_out   = tokens_out + VALUES(tokens_out)',
                [
                    'user_id'      => $userId,
                    'translations' => $isSuccess ? 1 : 0,
                    'failed'       => $isSuccess ? 0 : 1,
                    'chars'        => $isSuccess ? $requestChars : 0,
                    'tokens_in'    => $tokensIn,
                    'tokens_out'   => $tokensOut,
                ]
            );
        });
    }

    /**
     * Successful translations today (UTC).
     */
    public function todayCount(int $userId): int
    {
        return (int) Database::fetchValue(
            'SELECT COALESCE(translations, 0) FROM usage_daily
             WHERE user_id = :id AND usage_date = UTC_DATE()',
            ['id' => $userId]
        );
    }

    /**
     * Successful translations in the current calendar month (UTC).
     */
    public function monthCount(int $userId): int
    {
        return (int) Database::fetchValue(
            'SELECT COALESCE(SUM(translations), 0) FROM usage_daily
             WHERE user_id = :id
               AND usage_date >= DATE_FORMAT(UTC_DATE(), \'%Y-%m-01\')',
            ['id' => $userId]
        );
    }

    /**
     * @return array{today:int,month:int,total:int,chars_month:int}
     */
    public function userSummary(int $userId): array
    {
        $row = Database::fetchOne(
            'SELECT
                COALESCE(SUM(CASE WHEN usage_date = UTC_DATE() THEN translations END), 0) AS today,
                COALESCE(SUM(CASE WHEN usage_date >= DATE_FORMAT(UTC_DATE(), \'%Y-%m-01\') THEN translations END), 0) AS month,
                COALESCE(SUM(translations), 0) AS total,
                COALESCE(SUM(CASE WHEN usage_date >= DATE_FORMAT(UTC_DATE(), \'%Y-%m-01\') THEN chars END), 0) AS chars_month
             FROM usage_daily
             WHERE user_id = :id',
            ['id' => $userId]
        ) ?? [];

        return [
            'today'       => (int) ($row['today'] ?? 0),
            'month'       => (int) ($row['month'] ?? 0),
            'total'       => (int) ($row['total'] ?? 0),
            'chars_month' => (int) ($row['chars_month'] ?? 0),
        ];
    }

    /* ----------------------------------------------------------------
     | Admin dashboard aggregates
     * -------------------------------------------------------------- */

    /** @return array<string,int> */
    public function platformTotals(): array
    {
        $row = Database::fetchOne(
            'SELECT
                COUNT(*)                                                           AS requests_total,
                SUM(status = \'success\')                                           AS requests_success,
                SUM(status <> \'success\')                                          AS requests_failed,
                COALESCE(SUM(tokens_in), 0)                                        AS tokens_in,
                COALESCE(SUM(tokens_out), 0)                                       AS tokens_out,
                SUM(created_at >= UTC_DATE())                                      AS requests_today,
                SUM(created_at >= DATE_FORMAT(UTC_DATE(), \'%Y-%m-01\'))            AS requests_month
             FROM ai_usage_logs'
        ) ?? [];

        return [
            'requests_total'   => (int) ($row['requests_total'] ?? 0),
            'requests_success' => (int) ($row['requests_success'] ?? 0),
            'requests_failed'  => (int) ($row['requests_failed'] ?? 0),
            'tokens_in'        => (int) ($row['tokens_in'] ?? 0),
            'tokens_out'       => (int) ($row['tokens_out'] ?? 0),
            'requests_today'   => (int) ($row['requests_today'] ?? 0),
            'requests_month'   => (int) ($row['requests_month'] ?? 0),
        ];
    }

    /**
     * Daily request volume for the dashboard chart.
     *
     * @return list<array{day:string,total:int,success:int,failed:int}>
     */
    public function dailySeries(int $days = 14): array
    {
        $days = max(1, min(90, $days));

        /** @var list<array{day:string,total:int,success:int,failed:int}> $rows */
        $rows = Database::fetchAll(
            sprintf(
                'SELECT DATE(created_at) AS day,
                        COUNT(*) AS total,
                        SUM(status = \'success\') AS success,
                        SUM(status <> \'success\') AS failed
                 FROM ai_usage_logs
                 WHERE created_at >= (UTC_DATE() - INTERVAL %d DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY day ASC',
                $days
            )
        );

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function providerBreakdown(): array
    {
        return Database::fetchAll(
            'SELECT COALESCE(p.name, \'(deleted provider)\') AS provider_name,
                    COUNT(*)                                 AS requests,
                    SUM(l.status = \'success\')               AS success,
                    SUM(l.status <> \'success\')              AS failed,
                    COALESCE(SUM(l.tokens_in), 0)            AS tokens_in,
                    COALESCE(SUM(l.tokens_out), 0)           AS tokens_out,
                    COALESCE(ROUND(AVG(l.latency_ms)), 0)    AS avg_latency_ms
             FROM ai_usage_logs l
             LEFT JOIN ai_providers p ON p.id = l.provider_id
             GROUP BY l.provider_id, p.name
             ORDER BY requests DESC'
        );
    }

    /** @return list<array<string,mixed>> */
    public function dialectBreakdown(int $limit = 10): array
    {
        return Database::fetchAll(
            'SELECT d.name_en AS dialect, d.code, COUNT(h.id) AS uses
             FROM translation_history h
             INNER JOIN dialects d ON d.id = h.dialect_id
             GROUP BY d.id, d.name_en, d.code
             ORDER BY uses DESC
             LIMIT ' . max(1, min(50, $limit))
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentErrors(int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT l.created_at, l.status, l.http_status, l.error_code, l.error_message,
                    l.latency_ms, l.attempt_count, COALESCE(p.name, \'—\') AS provider_name
             FROM ai_usage_logs l
             LEFT JOIN ai_providers p ON p.id = l.provider_id
             WHERE l.status <> \'success\'
             ORDER BY l.id DESC
             LIMIT ' . max(1, min(100, $limit))
        );
    }

    /**
     * Retention housekeeping — usage logs older than N days are dropped.
     */
    public function pruneLogs(int $days = 180): int
    {
        return Database::execute(
            sprintf(
                'DELETE FROM ai_usage_logs WHERE created_at < (UTC_DATE() - INTERVAL %d DAY)',
                max(7, min(3650, $days))
            )
        );
    }
}
