<?php
/**
 * @var array<string,list<array<string,mixed>>> $groups
 * @var string                                  $csrf
 * @var \Haryanvi\Admin\AdminSession            $session
 */

use Haryanvi\Admin\View;

$groupTitles = [
    'general'  => ['General', 'Application identity and availability'],
    'limits'   => ['Usage limits', 'Server-enforced caps on translation volume'],
    'auth'     => ['Accounts', 'Registration and session policy'],
    'engine'   => ['Translation engine', 'Defaults applied when the user has not chosen'],
    'security' => ['Security', 'Transport and client policy'],
    'legal'    => ['Legal text', 'Shown inside the app'],
];

// Keep legal text last; it is the longest section.
$order = ['general', 'limits', 'auth', 'engine', 'security', 'legal'];
uksort($groups, static function (string $a, string $b) use ($order): int {
    $ai = array_search($a, $order, true);
    $bi = array_search($b, $order, true);

    return ($ai === false ? 99 : $ai) <=> ($bi === false ? 99 : $bi);
});
?>

<form method="post" action="?page=settings">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="action" value="settings_save">

    <?php foreach ($groups as $groupName => $rows): ?>
        <?php [$title, $subtitle] = $groupTitles[$groupName] ?? [ucfirst($groupName), '']; ?>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2><?= View::e($title) ?></h2>
                    <?php if ($subtitle !== ''): ?><p><?= View::e($subtitle) ?></p><?php endif; ?>
                </div>
            </div>

            <?php foreach ($rows as $row): ?>
                <?php
                $key   = (string) $row['setting_key'];
                $type  = (string) $row['value_type'];
                $value = (string) ($row['setting_value'] ?? '');
                $id    = 'setting-' . $key;
                $readOnly = !$session->canWrite();
                ?>

                <?php if ($type === 'bool'): ?>
                    <input type="hidden" name="bool_keys[]" value="<?= View::e($key) ?>">
                    <label class="checkbox">
                        <input type="checkbox" name="settings[<?= View::e($key) ?>]" value="1"
                            <?= in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? 'checked' : '' ?>
                            <?= $readOnly ? 'disabled' : '' ?>>
                        <span>
                            <strong style="color:var(--text)"><?= View::e($row['label']) ?></strong>
                            <?php if (($row['description'] ?? '') !== ''): ?>
                                <br><?= View::e($row['description']) ?>
                            <?php endif; ?>
                        </span>
                    </label>

                <?php elseif ($type === 'text'): ?>
                    <div class="field">
                        <label for="<?= View::e($id) ?>"><?= View::e($row['label']) ?></label>
                        <textarea id="<?= View::e($id) ?>" name="settings[<?= View::e($key) ?>]"
                                  class="<?= $groupName === 'legal' ? 'tall' : '' ?>"
                                  <?= $readOnly ? 'readonly' : '' ?>><?= View::e($value) ?></textarea>
                        <?php if (($row['description'] ?? '') !== ''): ?>
                            <div class="hint"><?= View::e($row['description']) ?></div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($type === 'int'): ?>
                    <div class="field">
                        <label for="<?= View::e($id) ?>"><?= View::e($row['label']) ?></label>
                        <input type="number" id="<?= View::e($id) ?>" name="settings[<?= View::e($key) ?>]"
                               min="0" value="<?= View::e($value) ?>" <?= $readOnly ? 'readonly' : '' ?>>
                        <?php if (($row['description'] ?? '') !== ''): ?>
                            <div class="hint"><?= View::e($row['description']) ?></div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="field">
                        <label for="<?= View::e($id) ?>"><?= View::e($row['label']) ?></label>
                        <input type="text" id="<?= View::e($id) ?>" name="settings[<?= View::e($key) ?>]"
                               value="<?= View::e($value) ?>" maxlength="2000"
                               <?= $readOnly ? 'readonly' : '' ?>>
                        <?php if (($row['description'] ?? '') !== ''): ?>
                            <div class="hint"><?= View::e($row['description']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="small faint mono" style="margin:-0.5rem 0 0.9rem">
                    <?= View::e($key) ?>
                    <?php if ((int) $row['is_public'] === 1): ?>
                        · <span class="badge badge-info">sent to the app</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($session->canWrite()): ?>
        <div class="card">
            <div class="form-actions" style="margin-top:0; border-top:none; padding-top:0">
                <button type="submit" class="btn btn-primary">Save all settings</button>
                <a class="btn btn-ghost" href="?page=settings">Discard changes</a>
                <span class="small faint">
                    A limit of <code>0</code> means unlimited. Changes take effect on the next request.
                </span>
            </div>
        </div>
    <?php endif; ?>
</form>
