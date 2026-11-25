<?php
/**
 * @var list<array<string,mixed>>         $rows
 * @var int                               $total
 * @var int                               $pageIndex
 * @var int                               $perPage
 * @var string                            $search
 * @var int                               $dialectId
 * @var list<\Haryanvi\Models\Dialect>    $allDialects
 * @var array<string,mixed>|null          $editing
 * @var bool                              $showForm
 * @var string                            $csrf
 * @var \Haryanvi\Admin\AdminSession      $session
 */

use Haryanvi\Admin\View;

$isEdit     = $editing !== null;
$totalPages = (int) max(1, ceil($total / $perPage));

$entryTypes = ['word' => 'Word', 'phrase' => 'Phrase', 'idiom' => 'Idiom', 'slang' => 'Slang', 'grammar' => 'Grammar'];
$registers  = ['neutral' => 'Neutral', 'respectful' => 'Respectful', 'informal' => 'Informal', 'funny' => 'Funny'];
$sourceLangs = ['hi' => 'Hindi', 'en' => 'English', 'hinglish' => 'Hinglish'];
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Regional vocabulary</h2>
            <p>
                Curated reference the engine shows the model alongside each request. Entries whose source
                term appears in the user's text are prioritised; nothing is ever find-and-replaced.
            </p>
        </div>
        <?php if ($session->canWrite() && !$showForm): ?>
            <a class="btn btn-primary" href="?page=vocabulary&new=1&dialect_id=<?= $dialectId ?>">Add entry</a>
        <?php endif; ?>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="page" value="vocabulary">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= View::e($search) ?>"
                   placeholder="Source or Haryanvi term" class="devanagari">
        </div>
        <div class="field">
            <label for="dialect_id">Dialect</label>
            <select id="dialect_id" name="dialect_id">
                <option value="0">All (including pan-Haryanvi)</option>
                <?php foreach ($allDialects as $dialect): ?>
                    <option value="<?= $dialect->id ?>" <?= $dialectId === $dialect->id ? 'selected' : '' ?>>
                        <?= View::e($dialect->nameEn) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Apply</button>
        <?php if ($search !== '' || $dialectId > 0): ?>
            <a class="btn btn-ghost" href="?page=vocabulary">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <div class="empty">
            <div class="empty-mark">◌</div>
            <h3>No vocabulary entries</h3>
            <p class="muted">
                <?= $search !== '' || $dialectId > 0
                    ? 'Nothing matches this filter.'
                    : 'Load database/seed.sql for the starter lexicon, or add entries by hand.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Haryanvi</th>
                        <th>Type</th>
                        <th>Dialect</th>
                        <th>Example</th>
                        <th class="num">Weight</th>
                        <?php if ($session->canWrite()): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $vocabId = (int) $row['id']; ?>
                        <tr>
                            <td class="devanagari"><?= View::e($row['source_term']) ?>
                                <div class="small faint"><?= View::e($sourceLangs[$row['source_lang']] ?? $row['source_lang']) ?></div>
                            </td>
                            <td class="devanagari">
                                <?= View::e($row['haryanvi_term']) ?>
                                <?php if (($row['roman_term'] ?? '') !== ''): ?>
                                    <div class="small faint mono"><?= View::e($row['roman_term']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <span class="badge badge-neutral"><?= View::e($entryTypes[$row['entry_type']] ?? $row['entry_type']) ?></span>
                                <?php if ((string) $row['register'] !== 'neutral'): ?>
                                    <div style="margin-top:0.25rem">
                                        <span class="badge badge-maroon"><?= View::e($row['register']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small muted">
                                <?= $row['dialect_name'] === null
                                    ? '<span class="faint">all dialects</span>'
                                    : View::e($row['dialect_name']) ?>
                            </td>
                            <td class="small devanagari muted">
                                <?php if (($row['example_source'] ?? '') !== ''): ?>
                                    <?= View::truncate($row['example_source'], 40) ?>
                                    <div class="faint">→ <?= View::truncate($row['example_haryanvi'], 40) ?></div>
                                <?php else: ?>
                                    <span class="faint">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?= View::e((string) $row['weight']) ?>
                                <?php if ((int) $row['is_active'] !== 1): ?>
                                    <div><span class="badge badge-neutral">off</span></div>
                                <?php endif; ?>
                            </td>

                            <?php if ($session->canWrite()): ?>
                                <td>
                                    <div class="btn-row">
                                        <a class="btn btn-sm" href="?page=vocabulary&edit=<?= $vocabId ?>&dialect_id=<?= $dialectId ?>">Edit</a>
                                        <form method="post" action="?page=vocabulary"
                                              onsubmit="return confirm('Delete this vocabulary entry?')">
                                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                            <input type="hidden" name="action" value="vocab_delete">
                                            <input type="hidden" name="vocab_id" value="<?= $vocabId ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
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
                        'page' => 'vocabulary', 'q' => $search, 'dialect_id' => $dialectId, 'p' => $pageIndex - 1,
                    ])) ?>">← Previous</a>
                <?php endif; ?>
                <span class="spacer"></span>
                <span>Page <?= $pageIndex ?> of <?= $totalPages ?> · <?= View::number($total) ?> entries</span>
                <span class="spacer"></span>
                <?php if ($pageIndex < $totalPages): ?>
                    <a class="btn btn-sm" href="<?= View::e(View::url([
                        'page' => 'vocabulary', 'q' => $search, 'dialect_id' => $dialectId, 'p' => $pageIndex + 1,
                    ])) ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($showForm && $session->canWrite()): ?>
    <div class="card">
        <div class="card-head">
            <div>
                <h2><?= $isEdit ? 'Edit vocabulary entry' : 'Add vocabulary entry' ?></h2>
                <p>Examples matter most — they teach the model how the term behaves in a sentence.</p>
            </div>
            <a class="btn btn-ghost" href="?page=vocabulary&dialect_id=<?= $dialectId ?>">Cancel</a>
        </div>

        <form method="post" action="?page=vocabulary">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="vocab_save">
            <input type="hidden" name="vocab_id" value="<?= $isEdit ? (int) $editing['id'] : 0 ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="v_source">Source term</label>
                    <input type="text" id="v_source" name="source_term" required maxlength="255" class="devanagari"
                           value="<?= View::e($editing['source_term'] ?? '') ?>" placeholder="पता">
                </div>

                <div class="field">
                    <label for="v_target">Haryanvi term</label>
                    <input type="text" id="v_target" name="haryanvi_term" required maxlength="255" class="devanagari"
                           value="<?= View::e($editing['haryanvi_term'] ?? '') ?>" placeholder="बेरा">
                </div>

                <div class="field">
                    <label for="v_roman">Roman spelling</label>
                    <input type="text" id="v_roman" name="roman_term" maxlength="255" spellcheck="false"
                           value="<?= View::e($editing['roman_term'] ?? '') ?>" placeholder="bera">
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="v_dialect">Dialect</label>
                    <select id="v_dialect" name="dialect_id">
                        <option value="0">All dialects (pan-Haryanvi)</option>
                        <?php
                        $selectedDialect = $isEdit
                            ? (int) ($editing['dialect_id'] ?? 0)
                            : $dialectId;
                        ?>
                        <?php foreach ($allDialects as $dialect): ?>
                            <option value="<?= $dialect->id ?>" <?= $selectedDialect === $dialect->id ? 'selected' : '' ?>>
                                <?= View::e($dialect->nameEn) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="v_type">Entry type</label>
                    <select id="v_type" name="entry_type">
                        <?php foreach ($entryTypes as $code => $label): ?>
                            <option value="<?= View::e($code) ?>"
                                <?= ($editing['entry_type'] ?? 'word') === $code ? 'selected' : '' ?>>
                                <?= View::e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="v_lang">Source language</label>
                    <select id="v_lang" name="source_lang">
                        <?php foreach ($sourceLangs as $code => $label): ?>
                            <option value="<?= View::e($code) ?>"
                                <?= ($editing['source_lang'] ?? 'hi') === $code ? 'selected' : '' ?>>
                                <?= View::e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="v_register">Register</label>
                    <select id="v_register" name="register">
                        <?php foreach ($registers as $code => $label): ?>
                            <option value="<?= View::e($code) ?>"
                                <?= ($editing['register'] ?? 'neutral') === $code ? 'selected' : '' ?>>
                                <?= View::e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="v_weight">Weight</label>
                    <input type="number" id="v_weight" name="weight" min="1" max="100"
                           value="<?= View::e((string) ($editing['weight'] ?? 50)) ?>">
                    <div class="hint">Higher entries are preferred when the prompt budget is tight.</div>
                </div>
            </div>

            <div class="field">
                <label for="v_note">Usage note</label>
                <input type="text" id="v_note" name="usage_note" maxlength="500" class="devanagari"
                       value="<?= View::e($editing['usage_note'] ?? '') ?>"
                       placeholder="When and how this term is used">
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="v_ex_src">Example (source)</label>
                    <input type="text" id="v_ex_src" name="example_source" maxlength="500" class="devanagari"
                           value="<?= View::e($editing['example_source'] ?? '') ?>" placeholder="मुझे नहीं पता">
                </div>

                <div class="field">
                    <label for="v_ex_tgt">Example (Haryanvi)</label>
                    <input type="text" id="v_ex_tgt" name="example_haryanvi" maxlength="500" class="devanagari"
                           value="<?= View::e($editing['example_haryanvi'] ?? '') ?>" placeholder="मन्ने बेरा ना">
                </div>
            </div>

            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1"
                    <?= ((int) ($editing['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                <span>Active — include this entry in prompt context</span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Add entry' ?></button>
                <a class="btn btn-ghost" href="?page=vocabulary&dialect_id=<?= $dialectId ?>">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>
