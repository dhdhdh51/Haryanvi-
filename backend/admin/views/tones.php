<?php
/**
 * @var list<array<string,mixed>>    $rows
 * @var array<string,mixed>|null     $editing
 * @var bool                         $showForm
 * @var string                       $csrf
 * @var \Haryanvi\Admin\AdminSession $session
 */

use Haryanvi\Admin\View;

$isEdit = $editing !== null;
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Tone presets</h2>
            <p>Each preset's instruction is appended to the prompt to set the conversational register.</p>
        </div>
        <?php if ($session->canWrite() && !$showForm): ?>
            <a class="btn btn-primary" href="?page=tones&new=1">Add tone</a>
        <?php endif; ?>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">
            <div class="empty-mark">◌</div>
            <h3>No tone presets</h3>
            <p class="muted">Load <code>database/seed.sql</code> to install the six starter tones.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tone</th>
                        <th>Prompt instruction</th>
                        <th>State</th>
                        <?php if ($session->canWrite()): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <div><?= View::e($row['name_en']) ?></div>
                                <div class="small devanagari muted"><?= View::e($row['name_hi']) ?></div>
                                <div class="small faint mono"><?= View::e($row['code']) ?></div>
                                <?php if ((int) $row['is_default'] === 1): ?>
                                    <span class="badge badge-info" style="margin-top:0.25rem">default</span>
                                <?php endif; ?>
                            </td>
                            <td class="small muted" style="max-width:34rem">
                                <?= View::e($row['prompt_instruction']) ?>
                            </td>
                            <td>
                                <span class="badge <?= (int) $row['is_active'] === 1 ? 'badge-ok' : 'badge-neutral' ?>">
                                    <?= (int) $row['is_active'] === 1 ? 'active' : 'hidden' ?>
                                </span>
                            </td>
                            <?php if ($session->canWrite()): ?>
                                <td>
                                    <a class="btn btn-sm" href="?page=tones&edit=<?= (int) $row['id'] ?>">Edit</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($showForm && $session->canWrite()): ?>
    <div class="card">
        <div class="card-head">
            <div>
                <h2><?= $isEdit ? 'Edit tone preset' : 'Add tone preset' ?></h2>
                <p>Describe the register in plain imperative English — it goes straight into the prompt.</p>
            </div>
            <a class="btn btn-ghost" href="?page=tones">Cancel</a>
        </div>

        <form method="post" action="?page=tones">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="tone_save">
            <input type="hidden" name="tone_id" value="<?= $isEdit ? (int) $editing['id'] : 0 ?>">

            <div class="form-grid">
                <?php if (!$isEdit): ?>
                    <div class="field">
                        <label for="code">Code</label>
                        <input type="text" id="code" name="code" required maxlength="40"
                               placeholder="e.g. formal_work" spellcheck="false">
                    </div>
                <?php else: ?>
                    <div class="field">
                        <div class="label">Code</div>
                        <input type="text" value="<?= View::e($editing['code']) ?>" disabled>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
                           value="<?= View::e((string) ($editing['sort_order'] ?? 100)) ?>">
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="name_en">Name (English)</label>
                    <input type="text" id="name_en" name="name_en" required maxlength="80"
                           value="<?= View::e($editing['name_en'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="name_hi">Name (Devanagari)</label>
                    <input type="text" id="name_hi" name="name_hi" maxlength="80" class="devanagari"
                           value="<?= View::e($editing['name_hi'] ?? '') ?>">
                </div>
            </div>

            <div class="field">
                <label for="description">Short description</label>
                <input type="text" id="description" name="description" maxlength="255"
                       value="<?= View::e($editing['description'] ?? '') ?>"
                       placeholder="Shown next to the tone in the app">
            </div>

            <div class="field">
                <label for="prompt_instruction">Prompt instruction</label>
                <textarea id="prompt_instruction" name="prompt_instruction" class="tall" required maxlength="4000"
                          placeholder="Use respectful address throughout. Prefer आप over तू ..."><?= View::e($editing['prompt_instruction'] ?? '') ?></textarea>
                <div class="hint">
                    Keep it behavioural. Avoid asking for humour or slang that would change the sender's
                    meaning.
                </div>
            </div>

            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1"
                    <?= ((int) ($editing['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                <span>Active — offer this tone in the app</span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Add tone' ?></button>
                <a class="btn btn-ghost" href="?page=tones">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>
