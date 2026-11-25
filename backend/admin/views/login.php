<?php
/**
 * @var string $appName
 * @var string $csrf
 * @var array{0:string,1:string}|null $flash
 */

use Haryanvi\Admin\View;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in — <?= View::e($appName) ?> admin</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <div class="brand-mark">ह</div>
            <h1><?= View::e($appName) ?></h1>
            <p>Administrator sign-in</p>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= View::e($flash[0]) ?>"><?= View::e($flash[1]) ?></div>
        <?php endif; ?>

        <form method="post" action="?page=login" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="login">

            <div class="field">
                <label for="username">Username or email</label>
                <input type="text" id="username" name="username" required autofocus
                       autocomplete="username" spellcheck="false">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required
                       autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center">
                Sign in
            </button>
        </form>

        <div class="auth-foot">
            Five failed attempts lock the account for 15 minutes.
        </div>
    </div>
</div>
</body>
</html>
