<?php
/**
 * @var array<string,mixed> $overview
 * @var int                 $seriesPeak
 */

use Haryanvi\Admin\View;

$users    = $overview['users'];
$requests = $overview['requests'];
$tokens   = $overview['tokens'];
$feedback = $overview['feedback'];
?>

<?php if ($overview['health'] !== []): ?>
    <?php foreach ($overview['health'] as $warning): ?>
        <div class="alert alert-<?= View::e($warning['level']) ?>"><?= View::e($warning['message']) ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="grid grid-4" style="margin-bottom:1.1rem">
    <div class="stat accent">
        <div class="stat-label">Registered users</div>
        <div class="stat-value"><?= View::number($users['total']) ?></div>
        <div class="stat-sub"><?= View::number($users['new_7d']) ?> new in the last 7 days</div>
    </div>

    <div class="stat">
        <div class="stat-label">Active users</div>
        <div class="stat-value"><?= View::number($users['active_7d']) ?></div>
        <div class="stat-sub"><?= View::number($users['active_30d']) ?> active in 30 days</div>
    </div>

    <div class="stat">
        <div class="stat-label">Translations today</div>
        <div class="stat-value"><?= View::number($requests['today']) ?></div>
        <div class="stat-sub"><?= View::number($requests['this_month']) ?> this month</div>
    </div>

    <div class="stat maroon">
        <div class="stat-label">Failed requests</div>
        <div class="stat-value"><?= View::number($requests['failed']) ?></div>
        <div class="stat-sub">
            <?php if ($requests['success_rate'] === null): ?>
                No requests yet
            <?php else: ?>
                <?= View::e((string) $requests['success_rate']) ?>% success rate
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-4" style="margin-bottom:1.1rem">
    <div class="stat">
        <div class="stat-label">Total API calls</div>
        <div class="stat-value"><?= View::compact($requests['total']) ?></div>
        <div class="stat-sub"><?= View::number($requests['successful']) ?> successful</div>
    </div>

    <div class="stat">
        <div class="stat-label">Tokens consumed</div>
        <div class="stat-value"><?= View::compact($tokens['total']) ?></div>
        <div class="stat-sub">
            <?= View::compact($tokens['in']) ?> in · <?= View::compact($tokens['out']) ?> out
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">Stored translations</div>
        <div class="stat-value"><?= View::compact($overview['translations']['stored']) ?></div>
        <div class="stat-sub">Only where users enabled history</div>
    </div>

    <div class="stat">
        <div class="stat-label">Feedback awaiting review</div>
        <div class="stat-value"><?= View::number($feedback['new']) ?></div>
        <div class="stat-sub">
            <a href="?page=feedback&status=new">Open review queue</a>
        </div>
    </div>
</div>

<!-- ---------------------------------------------------------------- -->
<div class="card">
    <div class="card-head">
        <div>
            <h2>Request volume</h2>
            <p>Last 14 days, UTC</p>
        </div>
    </div>

    <?php if ($overview['daily_series'] === []): ?>
        <div class="empty">
            <div class="empty-mark">—</div>
            <h3>No translation requests yet</h3>
            <p class="muted">The chart fills in once the app starts sending traffic.</p>
        </div>
    <?php else: ?>
        <div class="chart">
            <?php foreach ($overview['daily_series'] as $day): ?>
                <?php
                $total     = (int) $day['total'];
                $failed    = (int) $day['failed'];
                $succeeded = max(0, $total - $failed);
                $scale     = 100 / max(1, $seriesPeak);
                ?>
                <div class="chart-col" title="<?= View::e($day['day']) ?>: <?= $total ?> requests, <?= $failed ?> failed">
                    <?php if ($failed > 0): ?>
                        <div class="chart-bar failed" style="height:<?= round($failed * $scale, 2) ?>%"></div>
                    <?php endif; ?>
                    <div class="chart-bar" style="height:<?= round($succeeded * $scale, 2) ?>%"></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="chart-labels">
            <?php foreach ($overview['daily_series'] as $day): ?>
                <div class="chart-label"><?= View::e(gmdate('j/n', strtotime((string) $day['day'] . ' UTC') ?: time())) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="legend">
            <span><span class="legend-dot" style="background:var(--accent)"></span>Successful</span>
            <span><span class="legend-dot" style="background:var(--danger)"></span>Failed</span>
            <span class="faint">Peak: <?= View::number($seriesPeak) ?>/day</span>
        </div>
    <?php endif; ?>
</div>

<!-- ---------------------------------------------------------------- -->
<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Most used dialects</h2>
                <p>From stored translation history</p>
            </div>
        </div>

        <?php if ($overview['dialect_breakdown'] === []): ?>
            <div class="empty">
                <h3>Nothing to show yet</h3>
                <p class="muted">Dialect usage appears once users save translations.</p>
            </div>
        <?php else: ?>
            <?php $topUses = max(1, (int) ($overview['dialect_breakdown'][0]['uses'] ?? 1)); ?>
            <?php foreach ($overview['dialect_breakdown'] as $row): ?>
                <div class="bar-row">
                    <div class="bar-label" title="<?= View::e($row['dialect']) ?>"><?= View::e($row['dialect']) ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= round(((int) $row['uses'] / $topUses) * 100, 1) ?>%"></div>
                    </div>
                    <div class="bar-value"><?= View::number($row['uses']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>AI provider usage</h2>
                <p>Requests, failures and latency per provider</p>
            </div>
            <a class="btn btn-sm" href="?page=providers">Configure</a>
        </div>

        <?php if ($overview['provider_breakdown'] === []): ?>
            <div class="empty">
                <h3>No provider calls recorded</h3>
                <p class="muted">Add and activate a provider to start translating.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th class="num">Calls</th>
                            <th class="num">Failed</th>
                            <th class="num">Tokens</th>
                            <th class="num">Avg</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overview['provider_breakdown'] as $row): ?>
                            <tr>
                                <td><?= View::e($row['provider_name']) ?></td>
                                <td class="num"><?= View::number($row['requests']) ?></td>
                                <td class="num">
                                    <?php if ((int) $row['failed'] > 0): ?>
                                        <span class="badge badge-danger"><?= View::number($row['failed']) ?></span>
                                    <?php else: ?>
                                        <span class="faint">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= View::compact((int) $row['tokens_in'] + (int) $row['tokens_out']) ?></td>
                                <td class="num nowrap"><?= View::number($row['avg_latency_ms']) ?> ms</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ---------------------------------------------------------------- -->
<div class="card">
    <div class="card-head">
        <div>
            <h2>Recent failures</h2>
            <p>Most recent unsuccessful AI calls — no user text is logged</p>
        </div>
    </div>

    <?php if ($overview['recent_errors'] === []): ?>
        <div class="empty">
            <div class="empty-mark">✓</div>
            <h3>No failures recorded</h3>
            <p class="muted">Every AI call so far has succeeded.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Provider</th>
                        <th>Status</th>
                        <th>Error</th>
                        <th class="num">Tries</th>
                        <th class="num">Latency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overview['recent_errors'] as $row): ?>
                        <tr>
                            <td class="nowrap faint"><?= View::ago($row['created_at']) ?></td>
                            <td><?= View::e($row['provider_name']) ?></td>
                            <td><span class="badge badge-danger"><?= View::e($row['status']) ?></span></td>
                            <td>
                                <div class="mono small"><?= View::e($row['error_code'] ?? '—') ?></div>
                                <div class="small muted"><?= View::truncate($row['error_message'], 110) ?></div>
                            </td>
                            <td class="num"><?= View::e((string) $row['attempt_count']) ?></td>
                            <td class="num nowrap"><?= View::number($row['latency_ms']) ?> ms</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
