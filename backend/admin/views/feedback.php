<?php
/**
 * @var list<array<string,mixed>>    $rows
 * @var int                          $total
 * @var int                          $pageIndex
 * @var int                          $perPage
 * @var string                       $status
 * @var array<string,int>            $counts
 * @var list<array{reason:string,total:int}> $reasons
 * @var string                       $csrf
 * @var \Haryanvi\Admin\AdminSession $session
 */

use Haryanvi\Admin\View;

$totalPages = (int) max(1, ceil($total / $perPage));

$reasonLabels = [
    'wrong_meaning' => 'Wrong meaning',
    'wrong_dialect' => 'Wrong dialect',
    'unnatural'     => 'Sounds unnatural',
    'script_issue'  => 'Script problem',
    'offensive'     => 'Offensive',
    'other'         => 'Other',
];

$statusTabs = [
    'new'      => 'Awaiting review',
    'reviewed' => 'Reviewed',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Translation quality reports</h2>
            <p>
                Approving a report with a suggested wording adds it to the regional lexicon, where it
                shapes every future translation for that dialect.
            </p>
        </div>
    </div>

    <div class="filters">
        <?php foreach ($statusTabs as $code => $label): ?>
            <a class="btn btn-sm <?= $status === $code ? 'btn-primary' : '' ?>"
               href="?page=feedback&status=<?= View::e($code) ?>">
                <?= View::e($label) ?>
                <?php if (($counts[$code] ?? 0) > 0): ?>
                    <span class="badge badge-neutral"><?= View::number($counts[$code]) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($reasons !== []): ?>
        <div class="small muted" style="margin-bottom:1rem">
            Reported reasons:
            <?php foreach ($reasons as $index => $reason): ?>
                <?= $index > 0 ? ' · ' : '' ?>
                <?= View::e($reasonLabels[$reason['reason']] ?? $reason['reason']) ?>
                (<?= View::number($reason['total']) ?>)
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="empty">
            <div class="empty-mark">✓</div>
            <h3>Nothing in this queue</h3>
            <p class="muted">
                <?= $status === 'new'
                    ? 'No reports are waiting for review.'
                    : 'No reports have this status yet.' ?>
            </p>
        </div>
    <?php else: ?>
        <?php foreach ($rows as $row): ?>
            <?php
            $feedbackId = (int) $row['id'];
            $suggested  = trim((string) ($row['suggested_text'] ?? ''));
            $isPending  = (string) $row['status'] === 'new';
            ?>
            <div class="card" style="background:var(--surface-raised); margin-bottom:0.9rem">
                <div class="card-head">
                    <div>
                        <span class="badge badge-maroon"><?= View::e($reasonLabels[$row['reason']] ?? $row['reason']) ?></span>
                        <?php if ($row['dialect_name'] !== null): ?>
                            <span class="badge badge-neutral"><?= View::e($row['dialect_name']) ?></span>
                        <?php endif; ?>
                        <span class="badge <?= match ((string) $row['status']) {
                            'new'      => 'badge-warn',
                            'approved' => 'badge-ok',
                            'rejected' => 'badge-danger',
                            default    => 'badge-info',
                        } ?>"><?= View::e($row['status']) ?></span>
                        <p>
                            Reported <?= View::ago($row['created_at']) ?>
                            <?php if ($row['user_email'] !== null): ?>
                                by <?= View::e($row['user_email']) ?>
                            <?php else: ?>
                                <span class="faint">by a since-deleted account</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <span class="faint small mono">#<?= $feedbackId ?></span>
                </div>

                <div class="textpair">
                    <div class="textblock">
                        <div class="label">Original message</div>
                        <div class="devanagari"><?= View::e($row['source_text']) ?></div>
                    </div>

                    <div class="textblock wrong">
                        <div class="label">What the engine produced</div>
                        <div class="devanagari"><?= View::e($row['translated_text']) ?></div>
                    </div>

                    <?php if ($suggested !== ''): ?>
                        <div class="textblock suggested">
                            <div class="label">User's suggestion</div>
                            <div class="devanagari"><?= View::e($suggested) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (trim((string) ($row['comment'] ?? '')) !== ''): ?>
                    <div class="field" style="margin-top:0.8rem; margin-bottom:0">
                        <div class="label">Comment</div>
                        <div class="small muted devanagari"><?= View::e($row['comment']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($row['reviewed_at'] !== null): ?>
                    <p class="small faint" style="margin:0.8rem 0 0">
                        Reviewed by <?= View::e($row['reviewer'] ?? 'unknown') ?>
                        <?= View::ago($row['reviewed_at']) ?>
                        <?php if (trim((string) ($row['review_note'] ?? '')) !== ''): ?>
                            — <?= View::e($row['review_note']) ?>
                        <?php endif; ?>
                        <?php if ($row['applied_vocab_id'] !== null): ?>
                            · <a href="?page=vocabulary&edit=<?= (int) $row['applied_vocab_id'] ?>">added to lexicon</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($isPending): ?>
                    <form method="post" action="?page=feedback&status=<?= View::e($status) ?>" style="margin-top:1rem">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="action" value="feedback_review">
                        <input type="hidden" name="feedback_id" value="<?= $feedbackId ?>">

                        <div class="field">
                            <label for="note-<?= $feedbackId ?>">Review note (optional)</label>
                            <input type="text" id="note-<?= $feedbackId ?>" name="review_note" maxlength="500"
                                   placeholder="Why this was approved or rejected">
                        </div>

                        <?php if ($suggested !== '' && $session->canWrite()): ?>
                            <label class="checkbox">
                                <input type="checkbox" name="promote_to_vocabulary" value="1" checked>
                                <span>
                                    On approval, add
                                    "<span class="devanagari"><?= View::truncate($row['source_text'], 40) ?></span>
                                    → <span class="devanagari"><?= View::truncate($suggested, 40) ?></span>"
                                    to the regional lexicon
                                </span>
                            </label>
                        <?php endif; ?>

                        <div class="btn-row">
                            <button type="submit" name="status" value="approved" class="btn btn-primary">Approve</button>
                            <button type="submit" name="status" value="rejected" class="btn btn-danger">Reject</button>
                            <button type="submit" name="status" value="reviewed" class="btn">Mark reviewed</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <?php if ($pageIndex > 1): ?>
                    <a class="btn btn-sm" href="<?= View::e(View::url([
                        'page' => 'feedback', 'status' => $status, 'p' => $pageIndex - 1,
                    ])) ?>">← Previous</a>
                <?php endif; ?>
                <span class="spacer"></span>
                <span>Page <?= $pageIndex ?> of <?= $totalPages ?></span>
                <span class="spacer"></span>
                <?php if ($pageIndex < $totalPages): ?>
                    <a class="btn btn-sm" href="<?= View::e(View::url([
                        'page' => 'feedback', 'status' => $status, 'p' => $pageIndex + 1,
                    ])) ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
