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
            <h2>Regional dialect profiles</h2>
            <p>
                The guidance notes on each profile are injected directly into the AI prompt, so they are
                the main lever on how regional the output sounds.
            </p>
        </div>
        <?php if ($session->canWrite() && !$showForm): ?>
            <a class="btn btn-primary" href="?page=dialects&new=1">Add dialect</a>
        <?php endif; ?>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">
            <div class="empty-mark">◌</div>
            <h3>No dialects configured</h3>
            <p class="muted">Load <code>database/seed.sql</code> to install the five starter profiles.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Dialect</th>
                        <th>Region</th>
                        <th class="num">Lexicon</th>
                        <th class="num">Guidance</th>
                        <th>State</th>
                        <?php if ($session->canWrite()): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $dialectId = (int) $row['id'];
                        $isActive  = (int) $row['is_active'] === 1;
                        $isDefault = (int) $row['is_default'] === 1;
                        $notesLen  = mb_strlen((string) ($row['prompt_notes'] ?? ''));
                        ?>
                        <tr>
                            <td>
                                <div><?= View::e($row['name_en']) ?></div>
                                <div class="small devanagari muted"><?= View::e($row['name_hi']) ?></div>
                                <div class="small faint mono"><?= View::e($row['code']) ?></div>
                                <?php if ($isDefault): ?>
                                    <span class="badge badge-info" style="margin-top:0.25rem">default</span>
                                <?php endif; ?>
                            </td>
                            <td class="small muted"><?= View::e($row['region_label']) ?></td>
                            <td class="num">
                                <a href="?page=vocabulary&dialect_id=<?= $dialectId ?>">
                                    <?= View::number($row['vocab_count']) ?>
                                </a>
                            </td>
                            <td class="num small">
                                <?php if ($notesLen === 0): ?>
                                    <span class="badge badge-warn">none</span>
                                <?php else: ?>
                                    <span class="faint"><?= View::number($notesLen) ?> chars</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $isActive ? 'badge-ok' : 'badge-neutral' ?>">
                                    <?= $isActive ? 'active' : 'hidden' ?>
                                </span>
                            </td>

                            <?php if ($session->canWrite()): ?>
                                <td>
                                    <div class="btn-row">
                                        <a class="btn btn-sm" href="?page=dialects&edit=<?= $dialectId ?>">Edit</a>
                                        <?php if (!$isDefault && $isActive): ?>
                                            <form method="post" action="?page=dialects">
                                                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                                <input type="hidden" name="action" value="dialect_default">
                                                <input type="hidden" name="dialect_id" value="<?= $dialectId ?>">
                                                <button type="submit" class="btn btn-sm">Make default</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
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
                <h2><?= $isEdit ? 'Edit dialect' : 'Add dialect' ?></h2>
                <p>Write the guidance notes as instructions addressed to a translator.</p>
            </div>
            <a class="btn btn-ghost" href="?page=dialects">Cancel</a>
        </div>

        <form method="post" action="?page=dialects">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="dialect_save">
            <input type="hidden" name="dialect_id" value="<?= $isEdit ? (int) $editing['id'] : 0 ?>">

            <div class="form-grid">
                <?php if (!$isEdit): ?>
                    <div class="field">
                        <label for="code">Code</label>
                        <input type="text" id="code" name="code" required maxlength="40"
                               placeholder="e.g. mewat_nuh" spellcheck="false">
                        <div class="hint">Lowercase identifier the app sends. Cannot be changed later.</div>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <div class="label">Code</div>
                        <input type="text" value="<?= View::e($editing['code']) ?>" disabled>
                        <div class="hint">Fixed — the app references dialects by this code.</div>
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
                    <input type="text" id="name_en" name="name_en" required maxlength="100"
                           value="<?= View::e($editing['name_en'] ?? '') ?>"
                           placeholder="Mewat & Nuh">
                </div>

                <div class="field">
                    <label for="name_hi">Name (Devanagari)</label>
                    <input type="text" id="name_hi" name="name_hi" maxlength="100"
                           value="<?= View::e($editing['name_hi'] ?? '') ?>"
                           placeholder="मेवात व नूंह" class="devanagari">
                </div>
            </div>

            <div class="field">
                <label for="region_label">Region label</label>
                <input type="text" id="region_label" name="region_label" maxlength="150"
                       value="<?= View::e($editing['region_label'] ?? '') ?>"
                       placeholder="Districts or belt this variety covers">
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" maxlength="2000"
                          placeholder="Shown to users when they pick a dialect."><?= View::e($editing['description'] ?? '') ?></textarea>
            </div>

            <div class="field">
                <label for="prompt_notes">Prompt guidance</label>
                <textarea id="prompt_notes" name="prompt_notes" class="tall devanagari" maxlength="8000"
                          placeholder="Copula: हूँ→सूं, है→सै. Negation: नहीं है→कोन्या. ..."><?= View::e($editing['prompt_notes'] ?? '') ?></textarea>
                <div class="hint">
                    Injected verbatim into the system prompt. Be concrete: give the copula, negation,
                    pronouns, question words and a couple of example sentences. State explicitly which
                    neighbouring-language features must <em>not</em> leak in.
                </div>
            </div>

            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1"
                    <?= ((int) ($editing['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                <span>Active — offer this dialect in the app</span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Add dialect' ?></button>
                <a class="btn btn-ghost" href="?page=dialects">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>
