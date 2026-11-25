<?php

declare(strict_types=1);

use Haryanvi\Tests\Assert;
use Haryanvi\Tests\BrowserClient;

/**
 * Admin dashboard end-to-end tests.
 *
 * Walks the real sign-in flow (including the forced password rotation), then
 * loads every page and checks the CSRF and authorisation gates.
 *
 * Requires:
 *   HARYANVI_TEST_URL     base URL of the deployment under test
 *   HARYANVI_ADMIN_USER   admin username (default: admin)
 *   HARYANVI_ADMIN_PASS   its current password (default: the seeded one)
 */

$baseUrl = rtrim((string) getenv('HARYANVI_TEST_URL'), '/');

if ($baseUrl === '') {
    echo "  (skipped: HARYANVI_TEST_URL not set)\n";

    return;
}

Assert::startTest('Admin dashboard');

$username = (string) (getenv('HARYANVI_ADMIN_USER') ?: 'admin');
$password = (string) (getenv('HARYANVI_ADMIN_PASS') ?: 'ChangeMe!2026');
$rotated  = 'Rotated-Admin-' . bin2hex(random_bytes(5));

$browser = new BrowserClient($baseUrl);

/* =====================================================================
 | Unauthenticated access
 * =================================================================== */

$anonymous = $browser->get('/admin/');
Assert::same(200, $anonymous->status, 'Dashboard root serves the sign-in page');
Assert::true($anonymous->contains('Administrator sign-in'), 'Sign-in form rendered');
Assert::false($anonymous->contains('Dashboard'), 'No dashboard content before sign-in');
Assert::false($anonymous->hasPhpError(), 'Sign-in page renders without PHP errors');

$protected = $browser->get('/admin/?page=users');
Assert::true(
    $protected->contains('Administrator sign-in'),
    'A protected page falls back to the sign-in form when unauthenticated'
);
Assert::false($protected->contains('User accounts'), 'Protected content is not leaked');

/* ---- Security headers ---- */
Assert::same('DENY', $anonymous->header('X-Frame-Options'), 'Clickjacking protection header present');
Assert::same('nosniff', $anonymous->header('X-Content-Type-Options'), 'MIME sniffing disabled');
Assert::notNull($anonymous->header('Content-Security-Policy'), 'Content Security Policy sent');
Assert::true(
    str_contains((string) $anonymous->header('Cache-Control'), 'no-store'),
    'Dashboard responses are not cacheable'
);

/* =====================================================================
 | Sign-in
 * =================================================================== */

$csrf = $browser->csrfTokenFrom($anonymous);
Assert::greaterThan(32, strlen($csrf), 'Sign-in form carries a CSRF token');

/* ---- A missing/incorrect token must be rejected ---- */
$badCsrf = $browser->post('/admin/?page=login', [
    'action'   => 'login',
    'csrf'     => str_repeat('0', 64),
    'username' => $username,
    'password' => $password,
]);
Assert::same(303, $badCsrf->status, 'Login with a forged CSRF token is redirected');
$afterBadCsrf = $browser->get('/admin/?page=login');
Assert::false($afterBadCsrf->contains('Dashboard'), 'Forged CSRF token did not establish a session');

/* ---- Wrong password ---- */
$csrf       = $browser->csrfTokenFrom($afterBadCsrf);
$wrongLogin = $browser->post('/admin/?page=login', [
    'action'   => 'login',
    'csrf'     => $csrf,
    'username' => $username,
    'password' => 'definitely-not-the-password',
]);
Assert::same(303, $wrongLogin->status, 'Failed login redirects back to the form');
$afterWrong = $browser->get('/admin/?page=login');
Assert::true($afterWrong->contains('Incorrect username or password'), 'Failed login shows a neutral message');

/* ---- Correct password ---- */
$csrf  = $browser->csrfTokenFrom($afterWrong);
$login = $browser->post('/admin/?page=login', [
    'action'   => 'login',
    'csrf'     => $csrf,
    'username' => $username,
    'password' => $password,
]);
Assert::same(303, $login->status, 'Successful login redirects');
Assert::true($browser->hasSessionCookie(), 'Session cookie issued');

/* =====================================================================
 | Forced password rotation for the seeded account
 * =================================================================== */

$landing = $browser->get('/admin/?page=dashboard');
Assert::false($landing->hasPhpError(), 'Landing page renders without PHP errors');

$needsRotation = $landing->contains('Change your password');

if ($needsRotation) {
    Assert::true(true, 'Seeded account is forced to rotate its password before anything else');

    // Confirm the gate actually blocks other work.
    $blocked = $browser->get('/admin/?page=settings');
    Assert::true(
        $blocked->contains('Change your password'),
        'Every page is gated until the default password is replaced'
    );

    $csrf   = $browser->csrfTokenFrom($blocked);
    $tooShort = $browser->post('/admin/?page=password', [
        'action'           => 'change_password',
        'csrf'             => $csrf,
        'current_password' => $password,
        'new_password'     => 'short',
        'confirm_password' => 'short',
    ]);
    Assert::same(303, $tooShort->status, 'Short password rejected');
    Assert::true(
        $browser->get('/admin/?page=password')->contains('at least 12 characters'),
        'Short password produces a clear message'
    );

    $page     = $browser->get('/admin/?page=password');
    $csrf     = $browser->csrfTokenFrom($page);
    $mismatch = $browser->post('/admin/?page=password', [
        'action'           => 'change_password',
        'csrf'             => $csrf,
        'current_password' => $password,
        'new_password'     => $rotated,
        'confirm_password' => $rotated . 'x',
    ]);
    Assert::same(303, $mismatch->status, 'Mismatched confirmation rejected');
    Assert::true(
        $browser->get('/admin/?page=password')->contains('did not match'),
        'Mismatched confirmation produces a clear message'
    );

    $page    = $browser->get('/admin/?page=password');
    $csrf    = $browser->csrfTokenFrom($page);
    $changed = $browser->post('/admin/?page=password', [
        'action'           => 'change_password',
        'csrf'             => $csrf,
        'current_password' => $password,
        'new_password'     => $rotated,
        'confirm_password' => $rotated,
    ]);
    Assert::same(303, $changed->status, 'Password rotation accepted');

    $afterRotation = $browser->get('/admin/?page=dashboard');
    Assert::false($afterRotation->contains('Change your password'), 'Gate lifts after rotation');
    Assert::true($afterRotation->contains('Registered users'), 'Dashboard renders after rotation');
}

/* =====================================================================
 | Every page loads
 * =================================================================== */

$pages = [
    'dashboard'  => 'Registered users',
    'users'      => 'User accounts',
    'providers'  => 'Configured providers',
    'dialects'   => 'Regional dialect profiles',
    'vocabulary' => 'Regional vocabulary',
    'tones'      => 'Tone presets',
    'feedback'   => 'Translation quality reports',
    'settings'   => 'Usage limits',
    'admins'     => 'Administrator accounts',
    'audit'      => 'Administrator activity',
];

foreach ($pages as $slug => $marker) {
    $response = $browser->get('/admin/?page=' . $slug);

    Assert::same(200, $response->status, 'Page "' . $slug . '" returns 200');
    Assert::false($response->isErrorPage(), 'Page "' . $slug . '" is not the error page');
    Assert::false($response->hasPhpError(), 'Page "' . $slug . '" has no PHP errors');
    Assert::true($response->contains($marker), 'Page "' . $slug . '" rendered its content');
}

/* ---- Forms render on the create/edit routes ---- */
foreach ([
    'providers&new=1'  => 'Add provider',
    'dialects&new=1'   => 'Add dialect',
    'vocabulary&new=1' => 'Add vocabulary entry',
    'tones&new=1'      => 'Add tone preset',
] as $query => $marker) {
    $response = $browser->get('/admin/?page=' . $query);
    Assert::true($response->contains($marker), 'Form "' . $query . '" renders');
    Assert::false($response->hasPhpError(), 'Form "' . $query . '" has no PHP errors');
}

/* ---- Seeded reference data is visible ---- */
$dialectPage = $browser->get('/admin/?page=dialects');
foreach (['rohtak_jhajjar', 'hisar_bhiwani', 'sonipat_panipat', 'jind_kaithal', 'general'] as $code) {
    Assert::true($dialectPage->contains($code), 'Dialect "' . $code . '" listed in the dashboard');
}

// Ampersands in dialect names must arrive HTML-escaped, not raw.
Assert::true($dialectPage->contains('Rohtak &amp; Jhajjar'), 'Dialect names are HTML-escaped');
Assert::false($dialectPage->contains('Rohtak & Jhajjar'), 'Raw ampersand never reaches the markup');

$vocabPage = $browser->get('/admin/?page=vocabulary');
Assert::true($vocabPage->contains('बेरा'), 'Devanagari vocabulary renders correctly');

/* =====================================================================
 | Mutations require a valid CSRF token
 * =================================================================== */

$settingsPage = $browser->get('/admin/?page=settings');
$csrf         = $browser->csrfTokenFrom($settingsPage);
Assert::greaterThan(32, strlen($csrf), 'Settings form carries a CSRF token');

$forged = $browser->post('/admin/?page=settings', [
    'action'                  => 'settings_save',
    'csrf'                    => str_repeat('a', 64),
    'settings[app_name]'      => 'Hacked Name',
]);
Assert::same(303, $forged->status, 'Mutation with a forged token is redirected');
Assert::true(
    $browser->get('/admin/?page=settings')->contains('had expired'),
    'Forged token produces a "form expired" message'
);
Assert::false(
    $browser->get('/admin/?page=settings')->contains('Hacked Name'),
    'Forged token changed nothing'
);

/* ---- A genuine settings update works ---- */
$settingsPage = $browser->get('/admin/?page=settings');
$csrf         = $browser->csrfTokenFrom($settingsPage);
$newName      = 'Haryanvi AI ' . bin2hex(random_bytes(3));

$saved = $browser->post('/admin/?page=settings', [
    'action'                       => 'settings_save',
    'csrf'                         => $csrf,
    'settings[app_name]'           => $newName,
    'settings[free_daily_limit]'   => '77',
    'bool_keys[]'                  => 'maintenance_mode',
]);
Assert::same(303, $saved->status, 'Settings save redirects');

$afterSave = $browser->get('/admin/?page=settings');
Assert::true($afterSave->contains($newName), 'Updated app name persisted');
Assert::true($afterSave->contains('value="77"'), 'Updated daily limit persisted');

/* ---- Unknown setting keys are ignored, not inserted ---- */
$settingsPage = $browser->get('/admin/?page=settings');
$csrf         = $browser->csrfTokenFrom($settingsPage);
$injected     = $browser->post('/admin/?page=settings', [
    'action'                             => 'settings_save',
    'csrf'                               => $csrf,
    'settings[definitely_not_a_setting]' => 'injected',
]);
Assert::same(303, $injected->status, 'Unknown key submission redirects');
Assert::false(
    $browser->get('/admin/?page=settings')->contains('definitely_not_a_setting'),
    'Unknown setting keys are not created'
);

/* ---- Restore the app name ---- */
$settingsPage = $browser->get('/admin/?page=settings');
$csrf         = $browser->csrfTokenFrom($settingsPage);
$browser->post('/admin/?page=settings', [
    'action'                     => 'settings_save',
    'csrf'                       => $csrf,
    'settings[app_name]'         => 'Haryanvi AI',
    'settings[free_daily_limit]' => '50',
]);

/* =====================================================================
 | Vocabulary round trip through the dashboard
 * =================================================================== */

$vocabForm = $browser->get('/admin/?page=vocabulary&new=1');
$csrf      = $browser->csrfTokenFrom($vocabForm);
$marker    = 'परीक्षण' . bin2hex(random_bytes(3));

$createdVocab = $browser->post('/admin/?page=vocabulary', [
    'action'           => 'vocab_save',
    'csrf'             => $csrf,
    'vocab_id'         => '0',
    'dialect_id'       => '0',
    'entry_type'       => 'phrase',
    'source_lang'      => 'hi',
    'source_term'      => $marker,
    'haryanvi_term'    => 'जांच',
    'roman_term'       => 'jaanch',
    'register'         => 'neutral',
    'weight'           => '15',
    'is_active'        => '1',
    'usage_note'       => 'Created by the integration test.',
    'example_source'   => $marker . ' करो',
    'example_haryanvi' => 'जांच कर ले',
]);
Assert::same(303, $createdVocab->status, 'Vocabulary entry created via the dashboard');

$vocabList = $browser->get('/admin/?page=vocabulary&q=' . rawurlencode($marker));
Assert::true($vocabList->contains($marker), 'New vocabulary entry is searchable');

/* ---- Delete it again ---- */
if (preg_match('/name="vocab_id"\s+value="(\d+)"/', $vocabList->body, $m) === 1) {
    $csrf    = $browser->csrfTokenFrom($vocabList);
    $deleted = $browser->post('/admin/?page=vocabulary', [
        'action'   => 'vocab_delete',
        'csrf'     => $csrf,
        'vocab_id' => $m[1],
    ]);
    Assert::same(303, $deleted->status, 'Vocabulary entry deleted via the dashboard');

    // The search box echoes the query back, so assert on the results table:
    // an empty result set renders the empty state instead of rows.
    $afterDelete = $browser->get('/admin/?page=vocabulary&q=' . rawurlencode($marker));
    Assert::true(
        $afterDelete->contains('No vocabulary entries'),
        'Deleted vocabulary entry no longer appears in search results'
    );
    Assert::false($afterDelete->contains('जांच'), 'Deleted entry\'s Haryanvi term is gone');
}

/* =====================================================================
 | Audit trail recorded the work above
 * =================================================================== */

$audit = $browser->get('/admin/?page=audit');
Assert::true($audit->contains('admin.login'), 'Audit trail records sign-ins');
Assert::true($audit->contains('settings.updated'), 'Audit trail records settings changes');
Assert::true($audit->contains('vocabulary.created'), 'Audit trail records vocabulary changes');
Assert::true($audit->contains('admin.login_failed'), 'Audit trail records failed sign-in attempts');

/* =====================================================================
 | View templates are not directly reachable
 * =================================================================== */

$direct = $browser->get('/admin/views/layout.php');
Assert::true(
    $direct->status === 403 || $direct->status === 404 || !$direct->contains('<!doctype html>'),
    'View templates cannot be requested directly'
);

/* =====================================================================
 | Sign out
 * =================================================================== */

$dashboard = $browser->get('/admin/?page=dashboard');
$csrf      = $browser->csrfTokenFrom($dashboard);
$logout    = $browser->post('/admin/?page=dashboard', ['action' => 'logout', 'csrf' => $csrf]);
Assert::same(303, $logout->status, 'Sign-out redirects');

$afterLogout = $browser->get('/admin/?page=users');
Assert::true($afterLogout->contains('Administrator sign-in'), 'Session is gone after sign-out');

/* ---- Report the password so a re-run can authenticate ---- */
if ($needsRotation) {
    echo "  note: admin password rotated to " . $rotated . "\n";
    file_put_contents(sys_get_temp_dir() . '/haryanvi-admin-pass', $rotated);
}
