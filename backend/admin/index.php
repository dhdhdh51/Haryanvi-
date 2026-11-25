<?php
/**
 * Haryanvi AI — admin dashboard front controller.
 *
 * Server-rendered PHP with cookie sessions and CSRF protection. No build
 * step, no Node.js, no CDN dependency — it runs wherever the API runs.
 *
 * Navigation uses ?page=..., mutations are POST with a CSRF token and are
 * recorded in admin_activity_logs.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Haryanvi\Admin\AdminRepository;
use Haryanvi\Admin\AdminSession;
use Haryanvi\Admin\DashboardService;
use Haryanvi\Admin\View;
use Haryanvi\Core\Logger;
use Haryanvi\Models\Dialect;
use Haryanvi\Models\TonePreset;
use Haryanvi\Models\TranslationRequest;
use Haryanvi\Providers\ProviderFactory;
use Haryanvi\Repositories\DialectRepository;
use Haryanvi\Repositories\FeedbackRepository;
use Haryanvi\Repositories\ProviderRepository;
use Haryanvi\Repositories\UsageRepository;
use Haryanvi\Repositories\UserRepository;
use Haryanvi\Services\PasswordHasher;
use Haryanvi\Services\PromptBuilder;
use Haryanvi\Services\SettingsService;
use Haryanvi\Services\TextGuard;

/* ---------------------------------------------------------------------
 | Security headers for the browser surface
 * ------------------------------------------------------------------- */
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header(
    "Content-Security-Policy: default-src 'none'; "
    . "style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; "
    . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
);
header('Cache-Control: no-store, private');

$session = new AdminSession();
$view    = new View(__DIR__ . '/views', $session);

$users     = new UserRepository();
$dialects  = new DialectRepository();
$providers = new ProviderRepository();
$feedback  = new FeedbackRepository();
$usage     = new UsageRepository();
$admins    = new AdminRepository();

$page   = (string) ($_GET['page'] ?? 'dashboard');
$action = (string) ($_POST['action'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/* ---------------------------------------------------------------------
 | Helpers
 * ------------------------------------------------------------------- */

function redirect(string $target): never
{
    header('Location: ' . $target, true, 303);
    exit;
}

function postString(string $key, int $maxLength = 255): string
{
    $value = $_POST[$key] ?? '';

    if (!is_scalar($value)) {
        return '';
    }

    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';

    return mb_substr(trim($clean), 0, $maxLength);
}

function postInt(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;

    return is_numeric($value) ? (int) $value : $default;
}

function postBool(string $key): bool
{
    return isset($_POST[$key]) && in_array((string) $_POST[$key], ['1', 'on', 'true', 'yes'], true);
}

function queryInt(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? null;

    return is_numeric($value) ? (int) $value : $default;
}

function queryString(string $key, int $maxLength = 120): string
{
    $value = $_GET[$key] ?? '';

    return is_scalar($value) ? mb_substr(trim((string) $value), 0, $maxLength) : '';
}

/* ---------------------------------------------------------------------
 | Unauthenticated: login only
 * ------------------------------------------------------------------- */
if (!$session->isAuthenticated()) {
    if ($method === 'POST' && $action === 'login') {
        // The login form's token comes from the pre-auth session.
        if (!$session->verifyCsrf(postString('csrf', 128))) {
            $session->flash('danger', 'Your session expired before the form was submitted. Please try again.');
            redirect('?page=login');
        }

        $result = $session->attemptLogin(
            postString('username', 190),
            (string) ($_POST['password'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if (!$result['ok']) {
            $session->flash('danger', $result['message']);
            redirect('?page=login');
        }

        redirect('?page=dashboard');
    }

    $view->renderBare('login', [
        'pageTitle' => 'Sign in',
        'appName'   => SettingsService::getString('app_name', 'Haryanvi AI'),
        'flash'     => $session->takeFlash(),
        'csrf'      => $session->csrfToken(),
    ]);
    exit;
}

/* ---------------------------------------------------------------------
 | CSRF on every mutation
 * ------------------------------------------------------------------- */
if ($method === 'POST' && !$session->verifyCsrf(postString('csrf', 128))) {
    Logger::warning('Admin CSRF check failed', ['admin_id' => $session->id(), 'action' => $action]);
    $session->flash('danger', 'That form had expired. Nothing was changed — please try again.');
    redirect('?page=' . urlencode($page));
}

/* ---------------------------------------------------------------------
 | Forced password rotation
 * ------------------------------------------------------------------- */
if ($session->mustChangePassword() && $action !== 'change_password' && $action !== 'logout') {
    if ($method === 'POST') {
        $session->flash('warning', 'Change your password before making any other changes.');
    }

    $view->renderBare('password-change', [
        'pageTitle' => 'Change your password',
        'forced'    => true,
        'flash'     => $session->takeFlash(),
        'csrf'      => $session->csrfToken(),
    ]);
    exit;
}

/** Block write actions for moderators. */
function requireWriteAccess(AdminSession $session, string $page): void
{
    if (!$session->canWrite()) {
        $session->flash('danger', 'Your role has read-only access to this section.');
        redirect('?page=' . urlencode($page));
    }
}

/* =====================================================================
 | Actions
 * =================================================================== */
if ($method === 'POST') {
    switch ($action) {
        /* ---- Session ------------------------------------------------ */
        case 'logout':
            $session->logout(
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            redirect('?page=login');

            // no break — redirect() exits

        case 'change_password':
            $current = (string) ($_POST['current_password'] ?? '');
            $next    = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            $admin = $session->admin();

            if (!PasswordHasher::verify($current, (string) $admin['password_hash'])) {
                $session->flash('danger', 'Your current password is incorrect.');
                redirect('?page=password');
            }

            if (mb_strlen($next) < 12) {
                $session->flash('danger', 'Choose a password of at least 12 characters.');
                redirect('?page=password');
            }

            if (!hash_equals($next, $confirm)) {
                $session->flash('danger', 'The new passwords did not match.');
                redirect('?page=password');
            }

            if (hash_equals($current, $next)) {
                $session->flash('danger', 'The new password must differ from the current one.');
                redirect('?page=password');
            }

            $admins->updatePassword($session->id(), PasswordHasher::hash($next));
            $session->audit('admin.password_changed', 'admin_user', (string) $session->id());
            $session->flash('success', 'Password updated.');
            redirect('?page=dashboard');

            // no break

        /* ---- Users -------------------------------------------------- */
        case 'user_status':
            requireWriteAccess($session, 'users');

            $userId = postInt('user_id');
            $status = postString('status', 20);

            if ($userId <= 0 || !in_array($status, ['active', 'blocked'], true)) {
                $session->flash('danger', 'Invalid request.');
                redirect('?page=users');
            }

            $reason = $status === 'blocked' ? postString('reason', 255) : null;
            $users->setStatus($userId, $status, $reason === '' ? null : $reason);

            $session->audit('user.' . $status, 'user', (string) $userId, ['reason' => $reason]);
            $session->flash(
                'success',
                $status === 'blocked'
                    ? 'User blocked and their active sessions were revoked.'
                    : 'User unblocked.'
            );
            redirect('?page=users');

            // no break

        case 'user_limits':
            requireWriteAccess($session, 'users');

            $userId = postInt('user_id');

            if ($userId <= 0) {
                $session->flash('danger', 'Invalid request.');
                redirect('?page=users');
            }

            // An empty field means "inherit the global default".
            $dailyRaw   = postString('daily_limit', 12);
            $monthlyRaw = postString('monthly_limit', 12);

            $daily   = $dailyRaw === '' ? null : max(0, (int) $dailyRaw);
            $monthly = $monthlyRaw === '' ? null : max(0, (int) $monthlyRaw);

            $users->setLimits($userId, $daily, $monthly);
            $session->audit('user.limits', 'user', (string) $userId, [
                'daily'   => $daily,
                'monthly' => $monthly,
            ]);
            $session->flash('success', 'Usage limits updated.');
            redirect('?page=users');

            // no break

        /* ---- AI providers ------------------------------------------ */
        case 'provider_save':
            requireWriteAccess($session, 'providers');

            $providerId = postInt('provider_id');
            $name       = postString('name', 100);
            $driver     = postString('driver', 40);
            $baseUrl    = postString('base_url', 255);
            $model      = postString('model', 120);
            $apiKey     = trim((string) ($_POST['api_key'] ?? ''));

            $errors = [];

            if ($name === '') {
                $errors[] = 'A provider name is required.';
            }
            if (!ProviderFactory::isValidDriver($driver)) {
                $errors[] = 'Choose a valid driver.';
            }
            if (!str_starts_with(strtolower($baseUrl), 'https://')) {
                $errors[] = 'The base URL must start with https://.';
            }
            if ($model === '') {
                $errors[] = 'A model name is required.';
            }
            if ($providerId === 0 && $apiKey === '') {
                $errors[] = 'An API key is required when adding a provider.';
            }
            if (!\Haryanvi\Core\Crypto::isConfigured()) {
                $errors[] = 'config/config.php has no encryption key, so the API key cannot be stored. '
                          . 'Run: php bin/generate-key.php';
            }

            $headersRaw = trim((string) ($_POST['extra_headers'] ?? ''));
            if ($headersRaw !== '' && !is_array(json_decode($headersRaw, true))) {
                $errors[] = 'Extra headers must be a JSON object, for example {"X-Org":"abc"}.';
            }

            if ($errors !== []) {
                $session->flash('danger', implode(' ', $errors));
                redirect('?page=providers' . ($providerId > 0 ? '&edit=' . $providerId : '&new=1'));
            }

            $fields = [
                'name'            => $name,
                'driver'          => $driver,
                'base_url'        => rtrim($baseUrl, '/'),
                'model'           => $model,
                'temperature'     => max(0.0, min(2.0, (float) ($_POST['temperature'] ?? 0.3))),
                'max_tokens'      => max(64, min(32000, postInt('max_tokens', 1024))),
                'timeout_seconds' => max(5, min(120, postInt('timeout_seconds', 30))),
                'max_retries'     => max(0, min(5, postInt('max_retries', 2))),
                'extra_headers'   => $headersRaw === '' ? null : $headersRaw,
                'is_active'       => postBool('is_active') ? 1 : 0,
                'priority'        => max(1, min(999, postInt('priority', 100))),
            ];

            try {
                if ($providerId > 0) {
                    $providers->update($providerId, $fields, $apiKey === '' ? null : $apiKey);
                    $session->audit('provider.updated', 'ai_provider', (string) $providerId, [
                        'driver'      => $driver,
                        'model'       => $model,
                        'key_changed' => $apiKey !== '',
                    ]);
                    $session->flash('success', 'Provider updated.');
                } else {
                    $slug   = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-')) ?: 'provider';
                    $unique = $slug;
                    $suffix = 1;
                    while ($providers->slugExists($unique)) {
                        $unique = $slug . '-' . (++$suffix);
                    }

                    $newId = $providers->create($fields + ['slug' => $unique], $apiKey);

                    // The first provider configured becomes the default.
                    if (count($providers->allForAdmin()) === 1) {
                        $providers->setDefault($newId);
                    }

                    $session->audit('provider.created', 'ai_provider', (string) $newId, [
                        'driver' => $driver,
                        'model'  => $model,
                    ]);
                    $session->flash('success', 'Provider added. Use "Test" to verify the credentials.');
                }
            } catch (\Throwable $e) {
                Logger::exception($e, ['context' => 'provider_save']);
                $session->flash('danger', 'Could not save the provider: ' . $e->getMessage());
            }

            redirect('?page=providers');

            // no break

        case 'provider_toggle':
        case 'provider_default':
        case 'provider_delete':
            requireWriteAccess($session, 'providers');

            $providerId = postInt('provider_id');

            if ($providerId <= 0) {
                $session->flash('danger', 'Invalid request.');
                redirect('?page=providers');
            }

            if ($action === 'provider_toggle') {
                $activate = postBool('activate');
                $providers->setActive($providerId, $activate);
                $session->audit('provider.' . ($activate ? 'activated' : 'deactivated'), 'ai_provider', (string) $providerId);
                $session->flash('success', 'Provider ' . ($activate ? 'activated.' : 'deactivated.'));
            } elseif ($action === 'provider_default') {
                $providers->setDefault($providerId);
                $session->audit('provider.set_default', 'ai_provider', (string) $providerId);
                $session->flash('success', 'Default provider updated. It will be tried first.');
            } else {
                $providers->delete($providerId);
                $session->audit('provider.deleted', 'ai_provider', (string) $providerId);
                $session->flash('success', 'Provider deleted.');
            }

            redirect('?page=providers');

            // no break

        case 'provider_test':
            requireWriteAccess($session, 'providers');

            $providerId = postInt('provider_id');
            $config     = $providers->find($providerId);

            if ($config === null) {
                $session->flash('danger', 'Provider not found.');
                redirect('?page=providers');
            }

            $sample = postString('sample_text', 300);
            if ($sample === '') {
                $sample = 'मुझे नहीं पता कि वो कहाँ है';
            }

            try {
                $probeDialect = $dialects->resolveDialect(null, null, SettingsService::getString('default_dialect_code', 'general'))
                    ?? new Dialect(0, 'general', 'General Haryanvi', 'सामान्य हरियाणवी', 'All Haryana', '', '', true);
                $probeTone = $dialects->resolveTone(null, null, SettingsService::getString('default_tone_code', 'natural'))
                    ?? new TonePreset(0, 'natural', 'Natural', 'सहज', '', 'Speak naturally.', true);

                $request = new TranslationRequest(
                    $sample,
                    'hi',
                    \Haryanvi\Services\LanguageDetector::detect($sample),
                    $probeDialect,
                    $probeTone,
                    'devanagari',
                    true,
                    'api'
                );

                $guard  = TextGuard::protect($sample);
                $prompt = (new PromptBuilder())->build($request, $guard, []);

                $started  = microtime(true);
                $response = ProviderFactory::make($config)->complete($prompt['system'], $prompt['user']);
                $elapsed  = (int) round((microtime(true) - $started) * 1000);

                $providers->recordSuccess($providerId);
                $session->audit('provider.tested', 'ai_provider', (string) $providerId, ['result' => 'ok']);

                $_SESSION['_provider_test'] = [
                    'provider_id' => $providerId,
                    'ok'          => true,
                    'sample'      => $sample,
                    'reply'       => mb_substr($response->content, 0, 1200),
                    'tokens'      => $response->tokensIn . ' in / ' . $response->tokensOut . ' out',
                    'latency_ms'  => $elapsed,
                    'attempts'    => $response->attempts,
                ];

                $session->flash('success', 'Provider responded in ' . $elapsed . ' ms.');
            } catch (\Throwable $e) {
                $providers->recordError($providerId, $e->getMessage());
                $session->audit('provider.tested', 'ai_provider', (string) $providerId, ['result' => 'failed']);

                $_SESSION['_provider_test'] = [
                    'provider_id' => $providerId,
                    'ok'          => false,
                    'sample'      => $sample,
                    'reply'       => $e->getMessage(),
                ];

                $session->flash('danger', 'Provider test failed: ' . $e->getMessage());
            }

            redirect('?page=providers');

            // no break

        /* ---- Dialects ---------------------------------------------- */
        case 'dialect_save':
            requireWriteAccess($session, 'dialects');

            $dialectId = postInt('dialect_id');
            $code      = strtolower(preg_replace('/[^a-z0-9_]/i', '_', postString('code', 40)) ?? '');
            $nameEn    = postString('name_en', 100);

            if ($nameEn === '' || ($dialectId === 0 && $code === '')) {
                $session->flash('danger', 'A dialect needs a code and an English name.');
                redirect('?page=dialects');
            }

            $fields = [
                'name_en'      => $nameEn,
                'name_hi'      => postString('name_hi', 100),
                'region_label' => postString('region_label', 150),
                'description'  => postString('description', 2000),
                'prompt_notes' => postString('prompt_notes', 8000),
                'is_active'    => postBool('is_active') ? 1 : 0,
                'sort_order'   => max(0, min(9999, postInt('sort_order', 100))),
            ];

            if ($dialectId > 0) {
                $dialects->updateDialect($dialectId, $fields);
                $session->audit('dialect.updated', 'dialect', (string) $dialectId);
                $session->flash('success', 'Dialect updated.');
            } else {
                $newId = $dialects->createDialect($fields + ['code' => $code]);
                $session->audit('dialect.created', 'dialect', (string) $newId, ['code' => $code]);
                $session->flash('success', 'Dialect added.');
            }

            redirect('?page=dialects');

            // no break

        case 'dialect_default':
            requireWriteAccess($session, 'dialects');

            $dialectId = postInt('dialect_id');
            $dialect   = $dialectId > 0 ? $dialects->findDialectById($dialectId) : null;

            if ($dialect === null) {
                $session->flash('danger', 'Dialect not found or inactive.');
                redirect('?page=dialects');
            }

            $dialects->setDefaultDialect($dialectId);
            SettingsService::set('default_dialect_code', $dialect->code);

            $session->audit('dialect.set_default', 'dialect', (string) $dialectId, ['code' => $dialect->code]);
            $session->flash('success', 'Default dialect set to ' . $dialect->nameEn . '.');
            redirect('?page=dialects');

            // no break

        /* ---- Vocabulary -------------------------------------------- */
        case 'vocab_save':
            requireWriteAccess($session, 'vocabulary');

            $vocabId = postInt('vocab_id');
            $source  = postString('source_term', 255);
            $target  = postString('haryanvi_term', 255);

            if ($source === '' || $target === '') {
                $session->flash('danger', 'Both the source term and the Haryanvi term are required.');
                redirect('?page=vocabulary');
            }

            $dialectId = postInt('dialect_id');

            $fields = [
                'dialect_id'       => $dialectId > 0 ? $dialectId : null,
                'entry_type'       => in_array(postString('entry_type', 20), ['word', 'phrase', 'idiom', 'slang', 'grammar'], true)
                    ? postString('entry_type', 20) : 'word',
                'source_lang'      => in_array(postString('source_lang', 20), ['hi', 'en', 'hinglish'], true)
                    ? postString('source_lang', 20) : 'hi',
                'source_term'      => $source,
                'haryanvi_term'    => $target,
                'roman_term'       => postString('roman_term', 255) ?: null,
                'register'         => in_array(postString('register', 20), ['neutral', 'respectful', 'informal', 'funny'], true)
                    ? postString('register', 20) : 'neutral',
                'usage_note'       => postString('usage_note', 500) ?: null,
                'example_source'   => postString('example_source', 500) ?: null,
                'example_haryanvi' => postString('example_haryanvi', 500) ?: null,
                'weight'           => max(1, min(100, postInt('weight', 10))),
                'is_active'        => postBool('is_active') ? 1 : 0,
            ];

            if ($vocabId > 0) {
                $dialects->updateVocabulary($vocabId, $fields);
                $session->audit('vocabulary.updated', 'dialect_vocabulary', (string) $vocabId);
                $session->flash('success', 'Vocabulary entry updated.');
            } else {
                $newId = $dialects->createVocabulary($fields + ['created_by_admin' => $session->id()]);
                $session->audit('vocabulary.created', 'dialect_vocabulary', (string) $newId, [
                    'source' => $source,
                    'target' => $target,
                ]);
                $session->flash('success', 'Vocabulary entry added. It will shape future prompts.');
            }

            redirect('?page=vocabulary&dialect_id=' . $dialectId);

            // no break

        case 'vocab_delete':
            requireWriteAccess($session, 'vocabulary');

            $vocabId = postInt('vocab_id');

            if ($vocabId > 0) {
                $dialects->deleteVocabulary($vocabId);
                $session->audit('vocabulary.deleted', 'dialect_vocabulary', (string) $vocabId);
                $session->flash('success', 'Vocabulary entry deleted.');
            }

            redirect('?page=vocabulary');

            // no break

        /* ---- Tones ------------------------------------------------- */
        case 'tone_save':
            requireWriteAccess($session, 'tones');

            $toneId      = postInt('tone_id');
            $nameEn      = postString('name_en', 80);
            $instruction = postString('prompt_instruction', 4000);

            if ($nameEn === '' || $instruction === '') {
                $session->flash('danger', 'A tone needs a name and a prompt instruction.');
                redirect('?page=tones');
            }

            $fields = [
                'name_en'            => $nameEn,
                'name_hi'            => postString('name_hi', 80),
                'description'        => postString('description', 255),
                'prompt_instruction' => $instruction,
                'is_active'          => postBool('is_active') ? 1 : 0,
                'sort_order'         => max(0, min(9999, postInt('sort_order', 100))),
            ];

            if ($toneId > 0) {
                $dialects->updateTone($toneId, $fields);
                $session->audit('tone.updated', 'tone_preset', (string) $toneId);
                $session->flash('success', 'Tone preset updated.');
            } else {
                $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', postString('code', 40)) ?? '');

                if ($code === '') {
                    $session->flash('danger', 'A tone code is required.');
                    redirect('?page=tones');
                }

                $newId = $dialects->createTone($fields + ['code' => $code]);
                $session->audit('tone.created', 'tone_preset', (string) $newId, ['code' => $code]);
                $session->flash('success', 'Tone preset added.');
            }

            redirect('?page=tones');

            // no break

        /* ---- Translation quality ----------------------------------- */
        case 'feedback_review':
            $feedbackId = postInt('feedback_id');
            $status     = postString('status', 20);
            $note       = postString('review_note', 500);

            if ($feedbackId <= 0 || !in_array($status, ['reviewed', 'approved', 'rejected'], true)) {
                $session->flash('danger', 'Invalid request.');
                redirect('?page=feedback');
            }

            $report = $feedback->find($feedbackId);

            if ($report === null) {
                $session->flash('danger', 'Feedback not found.');
                redirect('?page=feedback');
            }

            $vocabId = null;

            // Approving with "add to lexicon" is the loop that turns a user
            // correction into better future translations.
            if ($status === 'approved' && postBool('promote_to_vocabulary') && $session->canWrite()) {
                $suggested = trim((string) ($report['suggested_text'] ?? ''));

                if ($suggested === '') {
                    $session->flash('warning', 'This report has no suggested wording, so nothing was added to the lexicon.');
                } else {
                    $vocabId = $dialects->createVocabulary([
                        'dialect_id'       => $report['dialect_id'] === null ? null : (int) $report['dialect_id'],
                        'entry_type'       => mb_strlen((string) $report['source_text']) > 30 ? 'phrase' : 'word',
                        'source_lang'      => 'hi',
                        'source_term'      => mb_substr((string) $report['source_text'], 0, 255),
                        'haryanvi_term'    => mb_substr($suggested, 0, 255),
                        'roman_term'       => null,
                        'register'         => 'neutral',
                        'usage_note'       => 'Added from user feedback #' . $feedbackId,
                        'example_source'   => mb_substr((string) $report['source_text'], 0, 500),
                        'example_haryanvi' => mb_substr($suggested, 0, 500),
                        'weight'           => 60,
                        'is_active'        => 1,
                        'created_by_admin' => $session->id(),
                    ]);

                    $session->flash('success', 'Feedback approved and added to the regional lexicon.');
                }
            }

            $feedback->setStatus($feedbackId, $status, $session->id(), $note === '' ? null : $note, $vocabId);
            $session->audit('feedback.' . $status, 'translation_feedback', (string) $feedbackId, [
                'promoted_vocab_id' => $vocabId,
            ]);

            if ($vocabId === null) {
                $session->flash('success', 'Feedback marked as ' . $status . '.');
            }

            redirect('?page=feedback&status=' . urlencode(queryString('status') ?: 'new'));

            // no break

        /* ---- System settings --------------------------------------- */
        case 'settings_save':
            requireWriteAccess($session, 'settings');

            $submitted = $_POST['settings'] ?? [];

            if (!is_array($submitted)) {
                $session->flash('danger', 'Invalid request.');
                redirect('?page=settings');
            }

            $rows    = SettingsService::grouped();
            $types   = [];
            foreach ($rows as $group) {
                foreach ($group as $row) {
                    $types[(string) $row['setting_key']] = (string) $row['value_type'];
                }
            }

            $updates = [];

            foreach ($submitted as $key => $value) {
                $key = (string) $key;

                // Only keys that already exist are writable; unknown keys are
                // ignored rather than inserted.
                if (!array_key_exists($key, $types)) {
                    continue;
                }

                $type = $types[$key];

                if ($type === 'bool') {
                    continue; // handled from the checkbox list below
                }

                if (!is_scalar($value)) {
                    continue;
                }

                $clean = (string) $value;
                $clean = str_replace(["\r\n", "\r"], "\n", $clean);

                if ($type === 'int') {
                    if (!is_numeric(trim($clean))) {
                        continue;
                    }
                    $clean = (string) max(0, (int) $clean);
                }

                if ($type === 'json' && trim($clean) !== '' && !is_array(json_decode($clean, true))) {
                    $session->flash('danger', 'The value for ' . $key . ' must be valid JSON.');
                    redirect('?page=settings');
                }

                $updates[$key] = mb_substr($clean, 0, 65535);
            }

            // Booleans: present in the POST means on, absent means off.
            $submittedBools = $_POST['bool_keys'] ?? [];
            if (is_array($submittedBools)) {
                foreach ($submittedBools as $key) {
                    $key = (string) $key;

                    if (($types[$key] ?? '') !== 'bool') {
                        continue;
                    }

                    $updates[$key] = isset($submitted[$key]) ? '1' : '0';
                }
            }

            $changed = SettingsService::setMany($updates);
            $session->audit('settings.updated', 'app_settings', null, ['keys' => $changed]);

            $session->flash(
                'success',
                $changed === []
                    ? 'No settings were changed.'
                    : sprintf('Updated %d setting%s.', count($changed), count($changed) === 1 ? '' : 's')
            );
            redirect('?page=settings');

            // no break

        /* ---- Administrators ---------------------------------------- */
        case 'admin_create':
            if (!$session->isSuperAdmin()) {
                $session->flash('danger', 'Only a super admin can create administrator accounts.');
                redirect('?page=admins');
            }

            $username = postString('username', 60);
            $email    = strtolower(postString('email', 190));
            $fullName = postString('full_name', 120);
            $role     = postString('role', 20);
            $password = (string) ($_POST['password'] ?? '');

            $errors = [];

            if (preg_match('/^[A-Za-z0-9._\-]{3,60}$/', $username) !== 1) {
                $errors[] = 'Username must be 3-60 characters (letters, digits, dot, dash, underscore).';
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'A valid email address is required.';
            }
            if (!in_array($role, ['super_admin', 'admin', 'moderator'], true)) {
                $errors[] = 'Choose a valid role.';
            }
            if (mb_strlen($password) < 12) {
                $errors[] = 'The password must be at least 12 characters.';
            }
            if ($errors === [] && $admins->usernameExists($username, $email)) {
                $errors[] = 'That username or email is already in use.';
            }

            if ($errors !== []) {
                $session->flash('danger', implode(' ', $errors));
                redirect('?page=admins');
            }

            $newId = $admins->create(
                $username,
                $email,
                PasswordHasher::hash($password),
                $fullName === '' ? $username : $fullName,
                $role
            );

            $session->audit('admin.created', 'admin_user', (string) $newId, [
                'username' => $username,
                'role'     => $role,
            ]);
            $session->flash('success', 'Administrator "' . $username . '" created.');
            redirect('?page=admins');

            // no break

        case 'admin_toggle':
            if (!$session->isSuperAdmin()) {
                $session->flash('danger', 'Only a super admin can enable or disable administrators.');
                redirect('?page=admins');
            }

            $targetId = postInt('admin_id');
            $activate = postBool('activate');

            if ($targetId === $session->id()) {
                $session->flash('danger', 'You cannot disable your own account.');
                redirect('?page=admins');
            }

            $target = $admins->find($targetId);

            if ($target === null) {
                $session->flash('danger', 'Administrator not found.');
                redirect('?page=admins');
            }

            // Never leave the installation without a way in.
            if (!$activate && (string) $target['role'] === 'super_admin' && $admins->countSuperAdmins() <= 1) {
                $session->flash('danger', 'This is the last active super admin and cannot be disabled.');
                redirect('?page=admins');
            }

            $admins->setActive($targetId, $activate);
            $session->audit('admin.' . ($activate ? 'enabled' : 'disabled'), 'admin_user', (string) $targetId);
            $session->flash('success', 'Administrator ' . ($activate ? 'enabled.' : 'disabled.'));
            redirect('?page=admins');

            // no break

        default:
            $session->flash('danger', 'Unrecognised action.');
            redirect('?page=dashboard');
    }
}

/* =====================================================================
 | Pages
 * =================================================================== */
$shared = [
    'flash'     => $session->takeFlash(),
    'csrf'      => $session->csrfToken(),
    'appName'   => SettingsService::getString('app_name', 'Haryanvi AI'),
];

try {
    switch ($page) {
        case 'login':
            redirect('?page=dashboard');

            // no break

        case 'password':
            $view->renderBare('password-change', $shared + [
                'pageTitle' => 'Change your password',
                'forced'    => false,
            ]);
            break;

        case 'users':
            $search    = queryString('q', 190);
            $status    = queryString('status', 20);
            $pageIndex = max(1, queryInt('p', 1));
            $result    = $users->paginateForAdmin($search, $status, $pageIndex, 25);

            $view->render('users', $shared + [
                'pageTitle'   => 'Users',
                'currentPage' => 'users',
                'rows'        => $result['rows'],
                'total'       => $result['total'],
                'pageIndex'   => $pageIndex,
                'perPage'     => 25,
                'search'      => $search,
                'status'      => $status,
                'defaults'    => [
                    'daily'   => SettingsService::freeDailyLimit(),
                    'monthly' => SettingsService::freeMonthlyLimit(),
                ],
            ]);
            break;

        case 'providers':
            $editId = queryInt('edit', 0);

            $testResult = $_SESSION['_provider_test'] ?? null;
            unset($_SESSION['_provider_test']);

            $view->render('providers', $shared + [
                'pageTitle'    => 'AI Configuration',
                'currentPage'  => 'providers',
                'rows'         => $providers->allForAdmin(),
                'editing'      => $editId > 0 ? $providers->findRaw($editId) : null,
                'showForm'     => $editId > 0 || isset($_GET['new']),
                'drivers'      => ProviderFactory::DRIVERS,
                'driverDefaults' => ProviderFactory::DRIVER_DEFAULTS,
                'testResult'   => is_array($testResult) ? $testResult : null,
                'cryptoReady'  => \Haryanvi\Core\Crypto::isConfigured(),
            ]);
            break;

        case 'dialects':
            $editId = queryInt('edit', 0);

            $view->render('dialects', $shared + [
                'pageTitle'   => 'Dialects',
                'currentPage' => 'dialects',
                'rows'        => $dialects->allDialectsForAdmin(),
                'editing'     => $editId > 0
                    ? \Haryanvi\Core\Database::fetchOne('SELECT * FROM dialects WHERE id = :id', ['id' => $editId])
                    : null,
                'showForm'    => $editId > 0 || isset($_GET['new']),
            ]);
            break;

        case 'vocabulary':
            $dialectId = queryInt('dialect_id', 0);
            $search    = queryString('q', 190);
            $pageIndex = max(1, queryInt('p', 1));
            $editId    = queryInt('edit', 0);

            $result = $dialects->paginateVocabulary(
                $dialectId > 0 ? $dialectId : null,
                $search,
                $pageIndex,
                30
            );

            $view->render('vocabulary', $shared + [
                'pageTitle'    => 'Regional vocabulary',
                'currentPage'  => 'vocabulary',
                'rows'         => $result['rows'],
                'total'        => $result['total'],
                'pageIndex'    => $pageIndex,
                'perPage'      => 30,
                'search'       => $search,
                'dialectId'    => $dialectId,
                'allDialects'  => $dialects->activeDialects(),
                'editing'      => $editId > 0 ? $dialects->findVocabulary($editId) : null,
                'showForm'     => $editId > 0 || isset($_GET['new']),
            ]);
            break;

        case 'tones':
            $editId = queryInt('edit', 0);

            $view->render('tones', $shared + [
                'pageTitle'   => 'Tone presets',
                'currentPage' => 'tones',
                'rows'        => $dialects->allTonesForAdmin(),
                'editing'     => $editId > 0
                    ? \Haryanvi\Core\Database::fetchOne('SELECT * FROM tone_presets WHERE id = :id', ['id' => $editId])
                    : null,
                'showForm'    => $editId > 0 || isset($_GET['new']),
            ]);
            break;

        case 'feedback':
            $status    = queryString('status', 20) ?: 'new';
            $pageIndex = max(1, queryInt('p', 1));
            $result    = $feedback->paginate($status, $pageIndex, 20);

            $view->render('feedback', $shared + [
                'pageTitle'   => 'Translation quality',
                'currentPage' => 'feedback',
                'rows'        => $result['rows'],
                'total'       => $result['total'],
                'pageIndex'   => $pageIndex,
                'perPage'     => 20,
                'status'      => $status,
                'counts'      => [
                    'new'      => $feedback->countByStatus('new'),
                    'reviewed' => $feedback->countByStatus('reviewed'),
                    'approved' => $feedback->countByStatus('approved'),
                    'rejected' => $feedback->countByStatus('rejected'),
                ],
                'reasons'     => $feedback->reasonBreakdown(),
            ]);
            break;

        case 'settings':
            $view->render('settings', $shared + [
                'pageTitle'   => 'System settings',
                'currentPage' => 'settings',
                'groups'      => SettingsService::grouped(),
            ]);
            break;

        case 'admins':
            $view->render('admins', $shared + [
                'pageTitle'   => 'Administrators',
                'currentPage' => 'admins',
                'rows'        => $admins->all(),
            ]);
            break;

        case 'audit':
            $pageIndex = max(1, queryInt('p', 1));
            $result    = $admins->auditTrail($pageIndex, 50);

            $view->render('audit', $shared + [
                'pageTitle'   => 'Activity log',
                'currentPage' => 'audit',
                'rows'        => $result['rows'],
                'total'       => $result['total'],
                'pageIndex'   => $pageIndex,
                'perPage'     => 50,
            ]);
            break;

        case 'dashboard':
        default:
            $dashboard = new DashboardService($users, $usage, $feedback, $providers);
            $overview  = $dashboard->overview();

            $view->render('dashboard', $shared + [
                'pageTitle'   => 'Overview',
                'currentPage' => 'dashboard',
                'overview'    => $overview,
                'seriesPeak'  => $dashboard->seriesPeak($overview['daily_series']),
            ]);
            break;
    }
} catch (\Throwable $e) {
    Logger::exception($e, ['context' => 'admin', 'page' => $page]);

    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Dashboard error</title>'
       . '<div style="font:15px/1.6 system-ui;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#e6e9f0;background:#0f1420">'
       . '<h1 style="font-size:1.25rem">Something went wrong</h1>'
       . '<p>The dashboard could not render this page. The details were written to the log.</p>'
       . (\Haryanvi\Core\Config::isDebug()
            ? '<pre style="white-space:pre-wrap;background:#161c2b;padding:1rem;border-radius:8px">'
              . View::e($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine())
              . '</pre>'
            : '')
       . '<p><a style="color:#4f8cff" href="?page=dashboard">Back to the dashboard</a></p></div>';
}
