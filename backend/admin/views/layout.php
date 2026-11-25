<?php
/**
 * Shared dashboard chrome.
 *
 * @var \Haryanvi\Admin\AdminSession $session
 * @var \Haryanvi\Admin\View         $view
 * @var string                       $content
 * @var string                       $currentPage
 * @var string                       $pageTitle
 * @var string                       $appName
 * @var array{0:string,1:string}|null $flash
 * @var string                       $csrf
 */

use Haryanvi\Admin\View;
use Haryanvi\Repositories\FeedbackRepository;

$pendingFeedback = (new FeedbackRepository())->countByStatus('new');

$navigation = [
    'Overview' => [
        ['dashboard', 'Dashboard', null],
    ],
    'People' => [
        ['users',  'Users',          null],
        ['admins', 'Administrators', null],
    ],
    'Translation engine' => [
        ['providers',  'AI configuration',    null],
        ['dialects',   'Dialects',            null],
        ['vocabulary', 'Regional vocabulary', null],
        ['tones',      'Tone presets',        null],
        ['feedback',   'Translation quality', $pendingFeedback > 0 ? $pendingFeedback : null],
    ],
    'System' => [
        ['settings', 'Settings',     null],
        ['audit',    'Activity log', null],
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= View::e($pageTitle) ?> — <?= View::e($appName) ?> admin</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="shell">

    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">ह</div>
            <div class="brand-text">
                <div class="brand-name"><?= View::e($appName) ?></div>
                <div class="brand-sub">Admin</div>
            </div>
        </div>

        <nav class="nav">
            <?php foreach ($navigation as $groupLabel => $items): ?>
                <div class="nav-group-label"><?= View::e($groupLabel) ?></div>
                <?php foreach ($items as [$slug, $label, $badge]): ?>
                    <a href="?page=<?= View::e($slug) ?>"
                       class="<?= $currentPage === $slug ? 'active' : '' ?>">
                        <span><?= View::e($label) ?></span>
                        <?php if ($badge !== null): ?>
                            <span class="nav-badge"><?= View::e((string) $badge) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <div><strong><?= View::e($session->username()) ?></strong></div>
            <div class="small"><?= View::e(str_replace('_', ' ', $session->role())) ?></div>
            <form method="post" action="?page=<?= View::e($currentPage) ?>" style="margin-top:0.7rem">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-sm btn-ghost">Sign out</button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <h1><?= View::e($pageTitle) ?></h1>
            <div class="topbar-meta">
                <a href="?page=password">Change password</a>
                &nbsp;·&nbsp;
                <?= View::e(gmdate('j M Y, H:i')) ?> UTC
            </div>
        </header>

        <main class="content">
            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= View::e($flash[0]) ?>"><?= View::e($flash[1]) ?></div>
            <?php endif; ?>

            <?php if (!$session->canWrite()): ?>
                <div class="alert alert-info">
                    Your role is <strong><?= View::e($session->role()) ?></strong>, which can review
                    translation quality but cannot change configuration or accounts.
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>
</body>
</html>
