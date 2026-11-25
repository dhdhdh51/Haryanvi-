<?php
/**
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var int                       $pageIndex
 * @var int                       $perPage
 */

use Haryanvi\Admin\View;

$totalPages = (int) max(1, ceil($total / $perPage));
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Administrator activity</h2>
            <p>
                Every configuration change, credential update and account action, newest first.
                <?= View::number($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?> recorded.
            </p>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">
            <div class="empty-mark">◌</div>
            <h3>No activity recorded yet</h3>
            <p class="muted">Administrative actions appear here as they happen.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Administrator</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $action = (string) $row['action'];
                        $badge  = match (true) {
                            str_contains($action, 'failed')   => 'badge-danger',
                            str_contains($action, 'deleted')  => 'badge-danger',
                            str_contains($action, 'created')  => 'badge-ok',
                            str_contains($action, 'login')    => 'badge-info',
                            default                          => 'badge-neutral',
                        };
                        ?>
                        <tr>
                            <td class="small nowrap faint" title="<?= View::e(View::datetime($row['created_at'])) ?>">
                                <?= View::ago($row['created_at']) ?>
                            </td>
                            <td class="small"><?= View::e($row['admin_username']) ?></td>
                            <td><span class="badge <?= $badge ?>"><?= View::e($action) ?></span></td>
                            <td class="small muted">
                                <?php if (($row['entity_type'] ?? '') !== ''): ?>
                                    <?= View::e($row['entity_type']) ?>
                                    <?php if (($row['entity_id'] ?? '') !== ''): ?>
                                        <span class="mono faint">#<?= View::e($row['entity_id']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="faint">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small mono faint" style="max-width:22rem; word-break:break-word">
                                <?= View::truncate($row['details'], 160) ?>
                            </td>
                            <td class="small mono faint"><?= View::e($row['ip_address'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <?php if ($pageIndex > 1): ?>
                    <a class="btn btn-sm" href="?page=audit&p=<?= $pageIndex - 1 ?>">← Newer</a>
                <?php endif; ?>
                <span class="spacer"></span>
                <span>Page <?= $pageIndex ?> of <?= $totalPages ?></span>
                <span class="spacer"></span>
                <?php if ($pageIndex < $totalPages): ?>
                    <a class="btn btn-sm" href="?page=audit&p=<?= $pageIndex + 1 ?>">Older →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
