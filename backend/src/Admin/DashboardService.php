<?php

declare(strict_types=1);

namespace Haryanvi\Admin;

use Haryanvi\Core\Database;
use Haryanvi\Repositories\FeedbackRepository;
use Haryanvi\Repositories\ProviderRepository;
use Haryanvi\Repositories\UsageRepository;
use Haryanvi\Repositories\UserRepository;
use Haryanvi\Services\SettingsService;

/**
 * Aggregates every figure the dashboard overview shows.
 */
final class DashboardService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UsageRepository $usage = new UsageRepository(),
        private readonly FeedbackRepository $feedback = new FeedbackRepository(),
        private readonly ProviderRepository $providers = new ProviderRepository(),
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $totals = $this->usage->platformTotals();

        $successRate = $totals['requests_total'] > 0
            ? round(($totals['requests_success'] / $totals['requests_total']) * 100, 1)
            : null;

        return [
            'users' => [
                'total'          => $this->users->countAll(),
                'active_7d'      => $this->users->countActiveSince(7),
                'active_30d'     => $this->users->countActiveSince(30),
                'blocked'        => $this->users->countBlocked(),
                'new_7d'         => $this->users->countRegisteredSince(7),
            ],
            'requests' => [
                'total'        => $totals['requests_total'],
                'successful'   => $totals['requests_success'],
                'failed'       => $totals['requests_failed'],
                'today'        => $totals['requests_today'],
                'this_month'   => $totals['requests_month'],
                'success_rate' => $successRate,
            ],
            'tokens' => [
                'in'    => $totals['tokens_in'],
                'out'   => $totals['tokens_out'],
                'total' => $totals['tokens_in'] + $totals['tokens_out'],
            ],
            'feedback' => [
                'new'      => $this->feedback->countByStatus('new'),
                'reviewed' => $this->feedback->countByStatus('reviewed'),
                'approved' => $this->feedback->countByStatus('approved'),
                'rejected' => $this->feedback->countByStatus('rejected'),
            ],
            'translations' => [
                'stored' => (int) Database::fetchValue('SELECT COUNT(*) FROM translation_history'),
            ],
            'daily_series'       => $this->usage->dailySeries(14),
            'provider_breakdown' => $this->usage->providerBreakdown(),
            'dialect_breakdown'  => $this->usage->dialectBreakdown(10),
            'recent_errors'      => $this->usage->recentErrors(10),
            'health'             => $this->health(),
        ];
    }

    /**
     * The setup problems an operator most often has, surfaced on the
     * dashboard rather than hidden in a log file.
     *
     * @return list<array{level:string,message:string}>
     */
    public function health(): array
    {
        $warnings = [];

        if (!$this->providers->hasAnyActive()) {
            $warnings[] = [
                'level'   => 'danger',
                'message' => 'No AI provider is active. Translation requests are failing with a '
                           . 'configuration error until you add one under AI Configuration.',
            ];
        }

        if (!\Haryanvi\Core\Crypto::isConfigured()) {
            $warnings[] = [
                'level'   => 'danger',
                'message' => 'The encryption key is missing from config/config.php. Provider API keys '
                           . 'cannot be stored or read. Run: php bin/generate-key.php',
            ];
        }

        if (SettingsService::isMaintenanceMode()) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => 'Maintenance mode is ON. The app is receiving HTTP 503 for everything '
                           . 'except settings.',
            ];
        }

        $defaultAdmin = Database::fetchValue(
            'SELECT 1 FROM admin_users WHERE username = \'admin\' AND must_change_password = 1 LIMIT 1'
        );

        if ($defaultAdmin !== null) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => 'The seeded "admin" account still has its default password. Change it, '
                           . 'or create your own account with php bin/create-admin.php and delete it.',
            ];
        }

        if (!\Haryanvi\Core\Config::getBool('security.force_https', true)) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => 'HTTPS enforcement is disabled in config.php. Bearer tokens and user text '
                           . 'would travel in clear text.',
            ];
        }

        if (\Haryanvi\Core\Config::isDebug()) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => 'Debug mode is ON. API errors include internal details. Turn it off in '
                           . 'config.php before going live.',
            ];
        }

        $logDirectory = \Haryanvi\Core\Config::getString('logging.path');
        if ($logDirectory !== '' && is_dir($logDirectory) && !is_writable($logDirectory)) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => 'The log directory is not writable, so errors are going to the PHP error '
                           . 'log instead: ' . $logDirectory,
            ];
        }

        return $warnings;
    }

    /**
     * Peak value in the daily series, used to scale the bar chart.
     *
     * @param list<array<string,mixed>> $series
     */
    public function seriesPeak(array $series): int
    {
        $peak = 0;

        foreach ($series as $row) {
            $peak = max($peak, (int) ($row['total'] ?? 0));
        }

        return max(1, $peak);
    }
}
