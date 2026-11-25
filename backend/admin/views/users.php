<?php
/**
 * @var list<array<string,mixed>>    $rows
 * @var int                          $total
 * @var int                          $pageIndex
 * @var int                          $perPage
 * @var string                       $search
 * @var string                       $status
 * @var array{daily:int,monthly:int} $defaults
 * @var string                       $csrf
 * @var \Haryanvi\Admin\AdminSession $session
 */

use Haryanvi\Admin\View;

$totalPages = (int) max(1, ceil($total / $perPage));
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>User accounts</h2>
            <p><?= View::number($total) ?> account<?= $total === 1 ? '' : 's' ?> matching the current filter</p>
        </div>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="page" value="users">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= View::e($search) ?>"
                   placeholder="Email or display name">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="blocked" <?= $status === 'blocked' ? 'selected' : '' ?>>Blocked</option>
            </select>
        </div>
        <button type="submit" class="btn">Apply</button>
        <?php if ($search !== '' || $status !== ''): ?>
            <a class="btn btn-ghost" href="?page=users">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <div class="empty">
            <div class="empty-mark">◌</div>
            <h3>No users found</h3>
            <p class="muted">
                <?= $search !== '' || $status !== ''
                    ? 'Try a different search or clear the filters.'
                    : 'Accounts appear here as soon as people register in the app.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Status</th>
                        <th class="num">Today</th>
                        <th class="num">Total</th>
                        <th>Limits</th>
                        <th>Last login</th>
                        <?php if ($session->canWrite()): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $userId    = (int) $row['id'];
                        $isBlocked = (string) $row['status'] === 'blocked';
                        $daily     = $row['daily_limit']   === null ? null : (int) $row['daily_limit'];
                        $monthly   = $row['monthly_limit'] === null ? null : (int) $row['monthly_limit'];
                        ?>
                        <tr>
                            <td>
                                <div><?= View::e($row['display_name']) ?></div>
                                <div class="small muted"><?= View::e($row['email']) ?></div>
                                <div class="small faint mono"><?= View::e($row['uuid']) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= $isBlocked ? 'badge-danger' : 'badge-ok' ?>">
                                    <?= View::e($row['status']) ?>
                                </span>
                            </td>
                            <td class="num"><?= View::number($row['translations_today']) ?></td>
                            <td class="num"><?= View::number($row['translations_total']) ?></td>
                            <td class="small nowrap">
                                <?php if ($daily === null && $monthly === null): ?>
                                    <span class="faint">
                                        default
                                        (<?= View::number($defaults['daily']) ?>/day)
                                    </span>
                                <?php else: ?>
                                    <?= $daily === null
                                        ? View::number($defaults['daily']) . ' <span class="faint">(default)</span>'
                                        : ($daily === 0 ? '<span class="badge badge-info">unlimited</span>' : View::number($daily)) ?>
                                    /day<br>
                                    <?= $monthly === null
                                        ? View::number($defaults['monthly']) . ' <span class="faint">(default)</span>'
                                        : ($monthly === 0 ? '<span class="badge badge-info">unlimited</span>' : View::number($monthly)) ?>
                                    /month
                                <?php endif; ?>
                            </td>
                            <td class="small nowrap faint"><?= View::ago($row['last_login_at']) ?></td>

                            <?php if ($session->canWrite()): ?>
                                <td>
                                    <div class="btn-row">
                                        <form method="post" action="?page=users">
                                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                            <input type="hidden" name="action" value="user_status">
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <input type="hidden" name="status" value="<?= $isBlocked ? 'active' : 'blocked' ?>">
                                            <?php if (!$isBlocked): ?>
                                                <input type="hidden" name="reason" value="Blocked from the admin dashboard">
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-sm <?= $isBlocked ? '' : 'btn-danger' ?>">
                                                <?= $isBlocked ? 'Unblock' : 'Block' ?>
                                            </button>
                                        </form>

                                        <details>
                                            <summary class="btn btn-sm" style="list-style:none">Limits</summary>
                                            <form method="post" action="?page=users" style="margin-top:0.5rem; min-width:14rem">
                                                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                                <input type="hidden" name="action" value="user_limits">
                                                <input type="hidden" name="user_id" value="<?= $userId ?>">

                                                <div class="field">
                                                    <label>Daily limit</label>
                                                    <input type="number" name="daily_limit" min="0" max="100000"
                                                           value="<?= $daily === null ? '' : $daily ?>"
                                                           placeholder="default">
                                                </div>
                                                <div class="field">
                                                    <label>Monthly limit</label>
                                                    <input type="number" name="monthly_limit" min="0" max="1000000"
                                                           value="<?= $monthly === null ? '' : $monthly ?>"
                                                           placeholder="default">
                                                    <div class="hint">Empty = global default. 0 = unlimited.</div>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-primary">Save limits</button>
                                            </form>
                                        </details>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <?php if ($pageIndex > 1): ?>
                    <a class="btn btn-sm" href="<?= View::e(View::url([
                        'page' => 'users', 'q' => $search, 'status' => $status, 'p' => $pageIndex - 1,
                    ])) ?>">← Previous</a>
                <?php endif; ?>
                <span class="spacer"></span>
                <span>Page <?= $pageIndex ?> of <?= $totalPages ?></span>
                <span class="spacer"></span>
                <?php if ($pageIndex < $totalPages): ?>
                    <a class="btn btn-sm" href="<?= View::e(View::url([
                        'page' => 'users', 'q' => $search, 'status' => $status, 'p' => $pageIndex + 1,
                    ])) ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2>About blocking and limits</h2>
    <p class="muted small" style="margin-bottom:0">
        Blocking an account immediately revokes every active bearer token, so the user is signed out of
        all devices on their next request. Usage limits are enforced on the server for every translation —
        the app's own counters are only for display and are never trusted.
    </p>
</div>
