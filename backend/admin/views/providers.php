<?php
/**
 * @var list<array<string,mixed>>      $rows
 * @var array<string,mixed>|null       $editing
 * @var bool                           $showForm
 * @var array<string,string>           $drivers
 * @var array<string,array<string,string>> $driverDefaults
 * @var array<string,mixed>|null       $testResult
 * @var bool                           $cryptoReady
 * @var string                         $csrf
 * @var \Haryanvi\Admin\AdminSession   $session
 */

use Haryanvi\Admin\View;

$isEdit = $editing !== null;
?>

<?php if (!$cryptoReady): ?>
    <div class="alert alert-danger">
        <strong>No encryption key configured.</strong>
        Provider API keys are stored encrypted, so nothing can be saved until a key exists.
        Run <code>php bin/generate-key.php</code> and paste the result into
        <code>config/config.php</code>.
    </div>
<?php endif; ?>

<?php if ($testResult !== null): ?>
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Provider test result</h2>
                <p>
                    <?php if ($testResult['ok']): ?>
                        Responded in <?= View::number($testResult['latency_ms']) ?> ms
                        · <?= View::e($testResult['tokens']) ?>
                        · <?= View::e((string) $testResult['attempts']) ?> attempt(s)
                    <?php else: ?>
                        The request failed
                    <?php endif; ?>
                </p>
            </div>
            <span class="badge <?= $testResult['ok'] ? 'badge-ok' : 'badge-danger' ?>">
                <?= $testResult['ok'] ? 'Working' : 'Failed' ?>
            </span>
        </div>

        <div class="textpair">
            <div class="textblock">
                <div class="label">Sample sent</div>
                <div class="devanagari"><?= View::e($testResult['sample']) ?></div>
            </div>
            <div class="textblock <?= $testResult['ok'] ? 'suggested' : 'wrong' ?>">
                <div class="label"><?= $testResult['ok'] ? 'Raw model reply' : 'Error' ?></div>
                <div class="<?= $testResult['ok'] ? 'devanagari' : 'mono small' ?>"
                     style="white-space:pre-wrap"><?= View::e($testResult['reply']) ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Configured providers</h2>
            <p>
                Tried in order: the default first, then ascending priority. If one fails permanently,
                the next is used automatically.
            </p>
        </div>
        <?php if ($session->canWrite() && !$showForm): ?>
            <a class="btn btn-primary" href="?page=providers&new=1">Add provider</a>
        <?php endif; ?>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">
            <div class="empty-mark">⚙</div>
            <h3>No AI provider configured</h3>
            <p class="muted">
                Translation requests currently fail with a clear configuration error.
                Add a provider to bring the service online.
            </p>
            <?php if ($session->canWrite()): ?>
                <p><a class="btn btn-primary" href="?page=providers&new=1">Add your first provider</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Driver / model</th>
                        <th>API key</th>
                        <th class="num">Priority</th>
                        <th>State</th>
                        <th>Last used</th>
                        <?php if ($session->canWrite()): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $providerId = (int) $row['id'];
                        $isActive   = (int) $row['is_active'] === 1;
                        $isDefault  = (int) $row['is_default'] === 1;
                        $hasKey     = (int) $row['has_key'] === 1;
                        $lastError  = trim((string) ($row['last_error'] ?? ''));
                        ?>
                        <tr>
                            <td>
                                <div><?= View::e($row['name']) ?></div>
                                <div class="small faint mono"><?= View::e($row['slug']) ?></div>
                                <?php if ($isDefault): ?>
                                    <span class="badge badge-info" style="margin-top:0.25rem">default</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div class="mono"><?= View::e($row['driver']) ?></div>
                                <div class="muted"><?= View::e($row['model']) ?></div>
                                <div class="faint"><?= View::truncate($row['base_url'], 44) ?></div>
                            </td>
                            <td class="small">
                                <?php if ($hasKey): ?>
                                    <span class="mono">…<?= View::e($row['api_key_last4']) ?></span>
                                    <div class="faint">encrypted</div>
                                <?php else: ?>
                                    <span class="badge badge-warn">missing</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= View::e((string) $row['priority']) ?></td>
                            <td>
                                <?php if (!$hasKey): ?>
                                    <span class="badge badge-warn">no key</span>
                                <?php elseif ($isActive): ?>
                                    <span class="badge badge-ok">active</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">inactive</span>
                                <?php endif; ?>

                                <?php if ($lastError !== ''): ?>
                                    <div class="small" style="margin-top:0.35rem">
                                        <span class="badge badge-danger">last error</span>
                                        <div class="faint" style="margin-top:0.2rem"><?= View::truncate($lastError, 90) ?></div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small faint nowrap"><?= View::ago($row['last_used_at']) ?></td>

                            <?php if ($session->canWrite()): ?>
                                <td>
                                    <div class="btn-row">
                                        <a class="btn btn-sm" href="?page=providers&edit=<?= $providerId ?>">Edit</a>

                                        <form method="post" action="?page=providers">
                                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                            <input type="hidden" name="action" value="provider_test">
                                            <input type="hidden" name="provider_id" value="<?= $providerId ?>">
                                            <button type="submit" class="btn btn-sm" <?= $hasKey ? '' : 'disabled' ?>>Test</button>
                                        </form>

                                        <form method="post" action="?page=providers">
                                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                            <input type="hidden" name="action" value="provider_toggle">
                                            <input type="hidden" name="provider_id" value="<?= $providerId ?>">
                                            <input type="hidden" name="activate" value="<?= $isActive ? '0' : '1' ?>">
                                            <button type="submit" class="btn btn-sm">
                                                <?= $isActive ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>

                                        <?php if (!$isDefault): ?>
                                            <form method="post" action="?page=providers">
                                                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                                <input type="hidden" name="action" value="provider_default">
                                                <input type="hidden" name="provider_id" value="<?= $providerId ?>">
                                                <button type="submit" class="btn btn-sm">Make default</button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="post" action="?page=providers"
                                              onsubmit="return confirm('Delete this provider? Its stored API key will be destroyed.')">
                                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                            <input type="hidden" name="action" value="provider_delete">
                                            <input type="hidden" name="provider_id" value="<?= $providerId ?>">
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
    <?php endif; ?>
</div>

<?php if ($showForm && $session->canWrite()): ?>
    <div class="card">
        <div class="card-head">
            <div>
                <h2><?= $isEdit ? 'Edit provider' : 'Add provider' ?></h2>
                <p>
                    The API key is encrypted with AES-256-GCM before it reaches the database and is never
                    displayed again.
                </p>
            </div>
            <a class="btn btn-ghost" href="?page=providers">Cancel</a>
        </div>

        <form method="post" action="?page=providers">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="provider_save">
            <input type="hidden" name="provider_id" value="<?= $isEdit ? (int) $editing['id'] : 0 ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="name">Display name</label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?= View::e($editing['name'] ?? '') ?>"
                           placeholder="e.g. OpenAI production">
                </div>

                <div class="field">
                    <label for="driver">Driver</label>
                    <select id="driver" name="driver" required>
                        <?php foreach ($drivers as $code => $label): ?>
                            <option value="<?= View::e($code) ?>"
                                <?= ($editing['driver'] ?? 'openai_compatible') === $code ? 'selected' : '' ?>>
                                <?= View::e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="base_url">Base URL</label>
                    <input type="text" id="base_url" name="base_url" required maxlength="255"
                           value="<?= View::e($editing['base_url'] ?? 'https://api.openai.com/v1') ?>"
                           placeholder="https://api.openai.com/v1" spellcheck="false">
                    <div class="hint">Must be HTTPS. See the reference below for each driver.</div>
                </div>

                <div class="field">
                    <label for="model">Model</label>
                    <input type="text" id="model" name="model" required maxlength="120"
                           value="<?= View::e($editing['model'] ?? '') ?>"
                           placeholder="gpt-4o-mini" spellcheck="false">
                </div>
            </div>

            <div class="field">
                <label for="api_key">API key</label>
                <input type="password" id="api_key" name="api_key" autocomplete="off" spellcheck="false"
                       placeholder="<?= $isEdit
                           ? ((int) ($editing['has_key'] ?? 0) === 1
                               ? 'Leave blank to keep the stored key (…' . View::e($editing['api_key_last4'] ?? '') . ')'
                               : 'No key stored — enter one')
                           : 'Paste the provider API key' ?>"
                       <?= $isEdit ? '' : 'required' ?>>
                <div class="hint">
                    Stored encrypted. Only the last four characters are ever shown again.
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="temperature">Temperature</label>
                    <input type="number" id="temperature" name="temperature" step="0.05" min="0" max="2"
                           value="<?= View::e((string) ($editing['temperature'] ?? '0.30')) ?>">
                    <div class="hint">0.2–0.4 keeps translations faithful.</div>
                </div>

                <div class="field">
                    <label for="max_tokens">Max output tokens</label>
                    <input type="number" id="max_tokens" name="max_tokens" min="64" max="32000"
                           value="<?= View::e((string) ($editing['max_tokens'] ?? 1024)) ?>">
                </div>

                <div class="field">
                    <label for="timeout_seconds">Timeout (seconds)</label>
                    <input type="number" id="timeout_seconds" name="timeout_seconds" min="5" max="120"
                           value="<?= View::e((string) ($editing['timeout_seconds'] ?? 30)) ?>">
                </div>

                <div class="field">
                    <label for="max_retries">Retries</label>
                    <input type="number" id="max_retries" name="max_retries" min="0" max="5"
                           value="<?= View::e((string) ($editing['max_retries'] ?? 2)) ?>">
                    <div class="hint">Applies to transient errors only.</div>
                </div>

                <div class="field">
                    <label for="priority">Priority</label>
                    <input type="number" id="priority" name="priority" min="1" max="999"
                           value="<?= View::e((string) ($editing['priority'] ?? 100)) ?>">
                    <div class="hint">Lower is tried first.</div>
                </div>
            </div>

            <div class="field">
                <label for="extra_headers">Extra headers (optional JSON)</label>
                <input type="text" id="extra_headers" name="extra_headers" spellcheck="false"
                       value="<?= View::e($editing['extra_headers'] ?? '') ?>"
                       placeholder='{"HTTP-Referer":"https://example.com"}'>
                <div class="hint">Some gateways (for example OpenRouter) ask for attribution headers.</div>
            </div>

            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1"
                    <?= ((int) ($editing['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                <span>Active — include this provider in the translation chain</span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $isEdit ? 'Save changes' : 'Add provider' ?>
                </button>
                <a class="btn btn-ghost" href="?page=providers">Cancel</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Driver reference</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Driver</th><th>Base URL</th><th>Example model</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($driverDefaults as $code => $defaults): ?>
                        <tr>
                            <td class="mono small"><?= View::e($code) ?></td>
                            <td class="mono small"><?= View::e($defaults['base_url']) ?></td>
                            <td class="mono small"><?= View::e($defaults['model']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small" style="margin-bottom:0">
            The <code>openai_compatible</code> driver also works with Groq, OpenRouter, Together,
            DeepSeek, Mistral and self-hosted servers such as vLLM or Ollama's OpenAI bridge — change
            only the base URL and model.
        </p>
    </div>
<?php endif; ?>
