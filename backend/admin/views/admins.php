<?php
/**
 * @var list<array<string,mixed>>    $rows
 * @var string                       $csrf
 * @var \Haryanvi\Admin\AdminSession $session
 */

use Haryanvi\Admin\View;

$roleLabels = [
    'super_admin' => 'Super admin',
    'admin'       => 'Admin',
    'moderator'   => 'Moderator',
];
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Administrator accounts</h2>
            <p>
                Super admins manage everything including other administrators. Admins manage
                configuration and users. Moderators can only review translation quality.
            </p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Role</th>
                    <th>State</th>
                    <th>Last login</th>
                    <?php if ($session->isSuperAdmin()): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $adminId  = (int) $row['id'];
                    $isActive = (int) $row['is_active'] === 1;
                    $isSelf   = $adminId === $session->id();
                    ?>
                    <tr>
                        <td>
                            <div>
                                <?= View::e($row['username']) ?>
                                <?php if ($isSelf): ?>
                                    <span class="badge badge-info">you</span>
                                <?php endif; ?>
                            </div>
                            <div class="small muted"><?= View::e($row['full_name']) ?></div>
                            <div class="small faint"><?= View::e($row['email']) ?></div>
                        </td>
                        <td>
                            <span class="badge <?= (string) $row['role'] === 'super_admin' ? 'badge-maroon' : 'badge-neutral' ?>">
                                <?= View::e($roleLabels[$row['role']] ?? $row['role']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $isActive ? 'badge-ok' : 'badge-neutral' ?>">
                                <?= $isActive ? 'active' : 'disabled' ?>
                            </span>
                            <?php if ((int) $row['must_change_password'] === 1): ?>
                                <div style="margin-top:0.25rem">
                                    <span class="badge badge-warn">default password</span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="small faint nowrap">
                            <?= View::ago($row['last_login_at']) ?>
                            <?php if (($row['last_login_ip'] ?? '') !== ''): ?>
                                <div class="mono"><?= View::e($row['last_login_ip']) ?></div>
                            <?php endif; ?>
                        </td>

                        <?php if ($session->isSuperAdmin()): ?>
                            <td>
                                <?php if (!$isSelf): ?>
                                    <form method="post" action="?page=admins">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <input type="hidden" name="action" value="admin_toggle">
                                        <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                        <input type="hidden" name="activate" value="<?= $isActive ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-danger' : '' ?>">
                                            <?= $isActive ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="faint small">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($session->isSuperAdmin()): ?>
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Add an administrator</h2>
                <p>Only a hash of the password is stored. Share the credentials over a secure channel.</p>
            </div>
        </div>

        <form method="post" action="?page=admins">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="admin_create">

            <div class="form-grid">
                <div class="field">
                    <label for="a_username">Username</label>
                    <input type="text" id="a_username" name="username" required
                           pattern="[A-Za-z0-9._\-]{3,60}" maxlength="60" spellcheck="false"
                           autocomplete="off">
                    <div class="hint">3–60 characters: letters, digits, dot, dash, underscore.</div>
                </div>

                <div class="field">
                    <label for="a_email">Email</label>
                    <input type="email" id="a_email" name="email" required maxlength="190" autocomplete="off">
                </div>

                <div class="field">
                    <label for="a_name">Full name</label>
                    <input type="text" id="a_name" name="full_name" maxlength="120" autocomplete="off">
                </div>

                <div class="field">
                    <label for="a_role">Role</label>
                    <select id="a_role" name="role" required>
                        <option value="moderator">Moderator — review translation quality only</option>
                        <option value="admin">Admin — manage configuration and users</option>
                        <option value="super_admin">Super admin — full access</option>
                    </select>
                </div>

                <div class="field">
                    <label for="a_password">Password</label>
                    <input type="password" id="a_password" name="password" required minlength="12"
                           autocomplete="new-password">
                    <div class="hint">At least 12 characters.</div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create administrator</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Command-line alternative</h2>
    <p class="muted small" style="margin-bottom:0">
        Administrators can also be created from the server shell, which is the recommended way to
        replace the seeded default account:
        <code>php bin/create-admin.php --username=yourname --role=super_admin</code>
    </p>
</div>
