<?php
/**
 * @var \Haryanvi\Admin\AdminSession $session
 * @var bool   $forced
 * @var string $csrf
 * @var array{0:string,1:string}|null $flash
 */

use Haryanvi\Admin\View;

$appName = $appName ?? 'Haryanvi AI';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Change password — <?= View::e($appName) ?> admin</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <div class="brand-mark">ह</div>
            <h1>Change your password</h1>
            <p>
                <?php if ($forced): ?>
                    This account still uses its default password. Set a new one to continue.
                <?php else: ?>
                    Signed in as <?= View::e($session->username()) ?>.
                <?php endif; ?>
            </p>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= View::e($flash[0]) ?>"><?= View::e($flash[1]) ?></div>
        <?php endif; ?>

        <form method="post" action="?page=password">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="field">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" required
                       autocomplete="current-password" autofocus>
            </div>

            <div class="field">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" required minlength="12"
                       autocomplete="new-password">
                <div class="hint">At least 12 characters. A passphrase of a few words works well.</div>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="12"
                       autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center">
                Update password
            </button>
        </form>

        <?php if (!$forced): ?>
            <div class="auth-foot"><a href="?page=dashboard">Back to the dashboard</a></div>
        <?php else: ?>
            <form method="post" action="?page=password" class="auth-foot">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-sm btn-ghost">Sign out instead</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
