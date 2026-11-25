<?php

declare(strict_types=1);

namespace Haryanvi\Services;

use Haryanvi\Core\ApiException;
use Haryanvi\Models\AuthenticatedUser;
use Haryanvi\Repositories\UsageRepository;

/**
 * Server-side usage quotas.
 *
 * The client is told its remaining allowance for display purposes only —
 * every limit is re-checked here before any AI call is made, so a patched
 * app cannot grant itself more translations.
 *
 * Resolution order per user: explicit per-user override, then the global
 * `free_daily_limit` / `free_monthly_limit` settings. A limit of 0 means
 * unlimited.
 */
final class QuotaService
{
    public function __construct(
        private readonly UsageRepository $usage = new UsageRepository(),
    ) {
    }

    public function dailyLimitFor(AuthenticatedUser $user): int
    {
        return $user->dailyLimit ?? SettingsService::freeDailyLimit();
    }

    public function monthlyLimitFor(AuthenticatedUser $user): int
    {
        return $user->monthlyLimit ?? SettingsService::freeMonthlyLimit();
    }

    /**
     * @throws ApiException when either quota is already spent
     */
    public function assertWithinQuota(AuthenticatedUser $user): void
    {
        $dailyLimit = $this->dailyLimitFor($user);

        if ($dailyLimit > 0) {
            $used = $this->usage->todayCount($user->id);

            if ($used >= $dailyLimit) {
                throw ApiException::quotaExceeded(
                    sprintf(
                        'You have used all %d translations for today. Your allowance resets at midnight UTC.',
                        $dailyLimit
                    ),
                    [
                        'scope'      => 'daily',
                        'limit'      => $dailyLimit,
                        'used'       => $used,
                        'resets_at'  => gmdate('Y-m-d\TH:i:s\Z', strtotime('tomorrow midnight UTC') ?: time()),
                    ]
                );
            }
        }

        $monthlyLimit = $this->monthlyLimitFor($user);

        if ($monthlyLimit > 0) {
            $used = $this->usage->monthCount($user->id);

            if ($used >= $monthlyLimit) {
                throw ApiException::quotaExceeded(
                    sprintf(
                        'You have used all %d translations for this month.',
                        $monthlyLimit
                    ),
                    [
                        'scope' => 'monthly',
                        'limit' => $monthlyLimit,
                        'used'  => $used,
                    ]
                );
            }
        }
    }

    /**
     * Snapshot for GET /api/v1/usage and the `meta` block on /translate.
     *
     * @return array<string,mixed>
     */
    public function snapshot(AuthenticatedUser $user): array
    {
        $summary      = $this->usage->userSummary($user->id);
        $dailyLimit   = $this->dailyLimitFor($user);
        $monthlyLimit = $this->monthlyLimitFor($user);

        return [
            'daily'   => [
                'used'      => $summary['today'],
                'limit'     => $dailyLimit,
                'remaining' => $dailyLimit > 0 ? max(0, $dailyLimit - $summary['today']) : null,
                'unlimited' => $dailyLimit === 0,
            ],
            'monthly' => [
                'used'      => $summary['month'],
                'limit'     => $monthlyLimit,
                'remaining' => $monthlyLimit > 0 ? max(0, $monthlyLimit - $summary['month']) : null,
                'unlimited' => $monthlyLimit === 0,
            ],
            'all_time' => [
                'translations' => $summary['total'],
            ],
            'characters_this_month' => $summary['chars_month'],
            'resets'                => [
                'daily_at'   => gmdate('Y-m-d\TH:i:s\Z', strtotime('tomorrow midnight UTC') ?: time()),
                'monthly_at' => gmdate('Y-m-01\TH:i:s\Z', strtotime('first day of next month UTC') ?: time()),
            ],
        ];
    }
}
