<?php

declare(strict_types=1);

use Haryanvi\Tests\Assert;
use Haryanvi\Tests\HttpClient;

/**
 * End-to-end API tests against a running instance.
 *
 * Requires:
 *   HARYANVI_TEST_URL      base URL of the deployment under test
 *   HARYANVI_MOCK_MODEL    optional; model name the seeded provider uses
 *                          (see tests/support/mock-ai-server.py)
 *
 * The suite registers its own throwaway account and deletes it at the end, so
 * it is safe to run repeatedly against a staging database.
 */

Assert::startTest('API integration');

$baseUrl = (string) getenv('HARYANVI_TEST_URL');
$client  = new HttpClient(rtrim($baseUrl, '/'));

$unique   = bin2hex(random_bytes(6));
$email    = 'itest+' . $unique . '@example.com';
$password = 'Correct-Horse-' . $unique;

/* =====================================================================
 | Health and public endpoints
 * =================================================================== */

$health = $client->get('/api/v1/health');
Assert::same(200, $health->status, 'GET /health returns 200');
Assert::same('ok', $health->data('status'), 'Health reports ok');
Assert::same('connected', $health->data('database'), 'Health reports the database connected');

$dialects = $client->get('/api/v1/dialects');
Assert::same(200, $dialects->status, 'GET /dialects returns 200 without a token');
Assert::greaterThan(4, count((array) $dialects->data('dialects')), 'At least five dialect profiles seeded');
Assert::greaterThan(5, count((array) $dialects->data('tones')), 'At least six tone presets seeded');
Assert::same('Haryanvi', $dialects->data('target_language.name_en'), 'Target language is Haryanvi');

$dialectCodes = array_column((array) $dialects->data('dialects'), 'code');
foreach (['general', 'rohtak_jhajjar', 'hisar_bhiwani', 'sonipat_panipat', 'jind_kaithal'] as $expected) {
    Assert::true(in_array($expected, $dialectCodes, true), 'Dialect "' . $expected . '" is available');
}

$settings = $client->get('/api/v1/settings');
Assert::same(200, $settings->status, 'GET /settings works unauthenticated');
Assert::notNull($settings->data('app.app_name'), 'Public settings expose the app name');
Assert::isNull($settings->data('app.rate_limit_per_minute'), 'Private settings are not exposed');
Assert::notNull($settings->data('app.privacy_policy'), 'Privacy policy is published to the app');

/* =====================================================================
 | Authentication
 * =================================================================== */

$bad = $client->post('/api/v1/auth/register', ['email' => 'nope', 'password' => 'x', 'display_name' => '']);
Assert::same(422, $bad->status, 'Invalid registration rejected with 422');
Assert::same('VALIDATION_FAILED', $bad->errorCode(), 'Validation error code returned');
Assert::same(3, count((array) ($bad->errorDetails()['fields'] ?? [])), 'All three field errors reported at once');

$registered = $client->post('/api/v1/auth/register', [
    'email'        => $email,
    'password'     => $password,
    'display_name' => 'Integration Test',
    'device'       => 'phpunit-sandbox',
]);
Assert::same(201, $registered->status, 'Registration returns 201');
Assert::notNull($registered->data('token'), 'Registration issues a bearer token');
Assert::same($email, $registered->data('user.email'), 'Registered email echoed back');
Assert::isNull($registered->data('user.password_hash'), 'Password hash never returned');

$duplicate = $client->post('/api/v1/auth/register', [
    'email'        => $email,
    'password'     => $password,
    'display_name' => 'Duplicate',
]);
Assert::same(409, $duplicate->status, 'Duplicate email rejected with 409');
Assert::same('EMAIL_ALREADY_REGISTERED', $duplicate->errorCode(), 'Duplicate email error code');

$wrongPassword = $client->post('/api/v1/auth/login', ['email' => $email, 'password' => 'wrong-password']);
Assert::same(401, $wrongPassword->status, 'Wrong password rejected with 401');
Assert::notContains('hash', strtolower($wrongPassword->errorMessage()), 'Auth failure leaks no internals');

$unknownUser = $client->post('/api/v1/auth/login', ['email' => 'nobody-' . $unique . '@example.com', 'password' => 'whatever']);
Assert::same(401, $unknownUser->status, 'Unknown account rejected with 401');
Assert::same(
    $wrongPassword->errorMessage(),
    $unknownUser->errorMessage(),
    'Unknown account and wrong password are indistinguishable'
);

$login = $client->post('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
Assert::same(200, $login->status, 'Login succeeds');
$token = (string) $login->data('token');
Assert::greaterThan(20, strlen($token), 'Login token looks like a real secret');
Assert::true($token !== (string) $registered->data('token'), 'Each login issues a distinct token');

$client->withToken($token);

/* ---- Token enforcement ---- */
$noToken = (new HttpClient(rtrim($baseUrl, '/')))->get('/api/v1/usage');
Assert::same(401, $noToken->status, 'Protected route rejects a missing token');
Assert::same('UNAUTHORIZED', $noToken->errorCode(), 'Missing token error code');

$badToken = (new HttpClient(rtrim($baseUrl, '/')))->withToken('not-a-real-token')->get('/api/v1/usage');
Assert::same(401, $badToken->status, 'Protected route rejects a forged token');

/* =====================================================================
 | Settings and preferences
 * =================================================================== */

$mySettings = $client->get('/api/v1/settings');
Assert::notNull($mySettings->data('preferences'), 'Authenticated settings include preferences');
Assert::same('general', $mySettings->data('preferences.dialect'), 'New account defaults to general Haryanvi');
Assert::same('natural', $mySettings->data('preferences.tone'), 'New account defaults to the natural tone');
Assert::same('devanagari', $mySettings->data('preferences.output_script'), 'Devanagari is the default script');
Assert::true((bool) $mySettings->data('preferences.save_history'), 'History is on by default');

$updated = $client->patch('/api/v1/settings', [
    'dialect'       => 'rohtak_jhajjar',
    'tone'          => 'friendly',
    'include_roman' => true,
]);
Assert::same(200, $updated->status, 'PATCH /settings succeeds');
Assert::same('rohtak_jhajjar', $updated->data('preferences.dialect'), 'Dialect preference saved');
Assert::same('friendly', $updated->data('preferences.tone'), 'Tone preference saved');
Assert::true((bool) $updated->data('preferences.include_roman'), 'Roman output preference saved');

$badDialect = $client->patch('/api/v1/settings', ['dialect' => 'not_a_region']);
Assert::same(422, $badDialect->status, 'Unknown dialect rejected');

$nothing = $client->patch('/api/v1/settings', ['unrelated_key' => 'x']);
Assert::same(422, $nothing->status, 'PATCH with no recognised field rejected');

/* =====================================================================
 | Translation
 * =================================================================== */

$empty = $client->post('/api/v1/translate', ['text' => '']);
Assert::same(422, $empty->status, 'Empty text rejected');

$tooLong = $client->post('/api/v1/translate', ['text' => str_repeat('अ', 5000)]);
Assert::same(422, $tooLong->status, 'Over-length text rejected before any AI call');

$malformed = $client->postRaw('/api/v1/translate', '{not json');
Assert::same(400, $malformed->status, 'Malformed JSON body rejected with 400');
Assert::same('MALFORMED_JSON', $malformed->errorCode(), 'Malformed JSON error code');

$providerConfigured = ($health->data('ai_provider') === 'configured');

if (!$providerConfigured) {
    // The spec demands a clear configuration error rather than a fake result.
    $attempt = $client->post('/api/v1/translate', ['text' => 'मैं ठीक हूँ']);
    Assert::same(503, $attempt->status, 'Translate returns 503 when no provider is configured');
    Assert::same('AI_PROVIDER_NOT_CONFIGURED', $attempt->errorCode(), 'Clear configuration error code');
    Assert::contains('admin', strtolower($attempt->errorMessage()), 'Error tells the operator where to fix it');
} else {
    $hindi = $client->post('/api/v1/translate', [
        'text'        => 'मैं ठीक हूँ, तुम कैसे हो?',
        'source_lang' => 'hi',
        'dialect'     => 'rohtak_jhajjar',
        'tone'        => 'friendly',
        'channel'     => 'app',
    ]);
    Assert::same(200, $hindi->status, 'Hindi to Haryanvi translation succeeds: ' . $hindi->describe());
    Assert::notNull($hindi->data('translated_text'), 'Translation text returned');
    Assert::true(trim((string) $hindi->data('translated_text')) !== '', 'Translation is not empty');
    Assert::same('rohtak_jhajjar', $hindi->data('dialect.code'), 'Requested dialect echoed back');
    Assert::same('friendly', $hindi->data('tone.code'), 'Requested tone echoed back');
    Assert::notNull($hindi->meta('usage.daily.used'), 'Usage meta returned with the translation');
    Assert::true((bool) $hindi->data('saved_to_history'), 'Translation saved to history by default');

    $historyId = (string) $hindi->data('history_id');
    Assert::same(
        1,
        preg_match('/^[0-9a-f-]{36}$/', $historyId),
        'History id is a UUID, not a sequential integer'
    );

    /* ---- English source ---- */
    $english = $client->post('/api/v1/translate', [
        'text'        => 'I am coming now, see you tomorrow',
        'source_lang' => 'en',
    ]);
    Assert::same(200, $english->status, 'English to Haryanvi translation succeeds');

    /* ---- Hinglish / auto-detect ---- */
    $hinglish = $client->post('/api/v1/translate', [
        'text'        => 'bhai kya kar raha hai',
        'source_lang' => 'auto',
    ]);
    Assert::same(200, $hinglish->status, 'Hinglish translation succeeds');
    Assert::same(
        'hinglish',
        $hinglish->data('detected_lang'),
        'Romanised Hindi reported as Hinglish, not English'
    );

    /* ---- Explicitly declared source language is echoed back ---- */
    $declared = $client->post('/api/v1/translate', [
        'text'        => 'Please send me the file',
        'source_lang' => 'en',
    ]);
    Assert::same('en', $declared->data('detected_lang'), 'Declared source language respected');

    /* ---- Protected spans survive the round trip ---- */
    $withLink = $client->post('/api/v1/translate', [
        'text'    => 'ये देख ले https://example.com/offer?id=99 अर मन्ने 849302 भेज दे',
        'channel' => 'share',
    ]);
    Assert::same(200, $withLink->status, 'Translation with a URL and an OTP succeeds');
    Assert::contains(
        'https://example.com/offer?id=99',
        (string) $withLink->data('translated_text'),
        'URL preserved byte-for-byte through translation'
    );
    Assert::contains(
        '849302',
        (string) $withLink->data('translated_text'),
        'OTP digits preserved through translation'
    );

    /* ---- Roman script output ---- */
    $roman = $client->post('/api/v1/translate', [
        'text'          => 'नमस्ते भाई',
        'output_script' => 'roman',
    ]);
    Assert::same(200, $roman->status, 'Roman script translation succeeds');
    Assert::same('roman', $roman->data('output_script'), 'Roman script echoed back');
    Assert::notNull($roman->data('roman_text'), 'Roman text returned when requested');
    Assert::same(
        $roman->data('roman_text'),
        $roman->data('primary_text'),
        'primary_text follows the requested script'
    );

    /* ---- Per-request history opt-out ---- */
    $noSave = $client->post('/api/v1/translate', [
        'text'         => 'ये सेव ना होणा चाहिए',
        'save_history' => false,
    ]);
    Assert::same(200, $noSave->status, 'Translation with save_history=false succeeds');
    Assert::false((bool) $noSave->data('saved_to_history'), 'Per-request opt-out respected');
    Assert::isNull($noSave->data('history_id'), 'No history id when the entry was not stored');

    /* =================================================================
     | History
     * =============================================================== */

    $history = $client->get('/api/v1/history');
    Assert::same(200, $history->status, 'GET /history succeeds');
    Assert::greaterThan(0, count((array) $history->data('items')), 'History contains the saved translations');
    Assert::notNull($history->meta('total'), 'History response includes pagination meta');

    $items = (array) $history->data('items');
    $ids   = array_column($items, 'id');
    Assert::false(
        in_array((string) $noSave->data('source_text'), array_column($items, 'source_text'), true),
        'Opted-out translation is absent from history'
    );

    $favourite = $client->patch('/api/v1/history/' . $historyId, ['is_favourite' => true]);
    Assert::same(200, $favourite->status, 'Marking a translation as favourite succeeds');
    Assert::true((bool) $favourite->data('item.is_favourite'), 'Favourite flag persisted');

    $favourites = $client->get('/api/v1/history?favourites=1');
    Assert::same(1, count((array) $favourites->data('items')), 'Favourites filter returns only the starred entry');

    $search = $client->get('/api/v1/history?q=' . rawurlencode('ठीक'));
    Assert::greaterThan(0, count((array) $search->data('items')), 'History search finds Devanagari text');

    $missing = $client->delete('/api/v1/history/00000000-0000-4000-8000-000000000000');
    Assert::same(404, $missing->status, 'Deleting an unknown entry returns 404');

    $notAUuid = $client->delete('/api/v1/history/1');
    Assert::same(404, $notAUuid->status, 'Sequential id probing returns 404, not another user\'s row');

    $deleted = $client->delete('/api/v1/history/' . $historyId);
    Assert::same(200, $deleted->status, 'Deleting an own entry succeeds');

    /* =================================================================
     | Feedback
     * =============================================================== */

    $feedback = $client->post('/api/v1/feedback', [
        'reason'          => 'unnatural',
        'source_text'     => 'मैं ठीक हूँ',
        'translated_text' => 'मैं ठीक है',
        'suggested_text'  => 'मैं ठीक सूं',
        'comment'         => 'Copula should be सूं for first person.',
        'dialect'         => 'rohtak_jhajjar',
    ]);
    Assert::same(201, $feedback->status, 'Submitting feedback succeeds');
    Assert::notNull($feedback->data('feedback_id'), 'Feedback id returned');

    $badReason = $client->post('/api/v1/feedback', ['reason' => 'because', 'source_text' => 'a', 'translated_text' => 'b']);
    Assert::same(422, $badReason->status, 'Unknown feedback reason rejected');

    /* =================================================================
     | Usage
     * =============================================================== */

    $usage = $client->get('/api/v1/usage');
    Assert::same(200, $usage->status, 'GET /usage succeeds');
    Assert::greaterThan(0, (int) $usage->data('usage.daily.used'), 'Daily usage counted the translations');
    Assert::notNull($usage->data('usage.daily.limit'), 'Daily limit reported');
    Assert::notNull($usage->data('usage.resets.daily_at'), 'Quota reset time reported');
}

/* =====================================================================
 | History clear and privacy switch
 * =================================================================== */

$cleared = $client->delete('/api/v1/history');
Assert::same(200, $cleared->status, 'Clearing all history succeeds');

$afterClear = $client->get('/api/v1/history');
Assert::same(0, count((array) $afterClear->data('items')), 'History is empty after clearing');

$disable = $client->patch('/api/v1/settings', ['save_history' => false]);
Assert::same(200, $disable->status, 'Disabling history succeeds');
Assert::false((bool) $disable->data('preferences.save_history'), 'History disabled in preferences');

$uploadBlocked = $client->post('/api/v1/history', [
    'source_text'     => 'x',
    'translated_text' => 'y',
]);
Assert::same(403, $uploadBlocked->status, 'History upload refused while history is disabled');

$client->patch('/api/v1/settings', ['save_history' => true]);

$upload = $client->post('/api/v1/history', [
    'source_text'     => 'कल मिलते हैं',
    'translated_text' => 'काल मिलांगे',
    'roman_text'      => 'kaal milaange',
    'channel'         => 'keyboard',
]);
Assert::same(201, $upload->status, 'History upload succeeds when history is enabled');
Assert::same('keyboard', $upload->data('item.channel'), 'Upload records the originating channel');

/* =====================================================================
 | Routing and method handling
 * =================================================================== */

$notFound = $client->get('/api/v1/does-not-exist');
Assert::same(404, $notFound->status, 'Unknown endpoint returns 404');
Assert::same('NOT_FOUND', $notFound->errorCode(), 'Unknown endpoint error code');

$wrongMethod = $client->post('/api/v1/dialects');
Assert::same(405, $wrongMethod->status, 'Wrong HTTP method returns 405');
Assert::same('METHOD_NOT_ALLOWED', $wrongMethod->errorCode(), 'Method error code');

/* =====================================================================
 | Password change invalidates other sessions
 * =================================================================== */

$secondSession = (new HttpClient(rtrim($baseUrl, '/')))
    ->withToken((string) $registered->data('token'));
Assert::same(200, $secondSession->get('/api/v1/usage')->status, 'Second session is valid before the password change');

$newPassword = $password . '-rotated';
$changed     = $client->post('/api/v1/auth/password', [
    'current_password' => $password,
    'new_password'     => $newPassword,
]);
Assert::same(200, $changed->status, 'Password change succeeds');
Assert::same(401, $secondSession->get('/api/v1/usage')->status, 'Other sessions revoked after a password change');

$reLogin = $client->post('/api/v1/auth/login', ['email' => $email, 'password' => $newPassword]);
Assert::same(200, $reLogin->status, 'Login works with the new password');
$client->withToken((string) $reLogin->data('token'));

/* =====================================================================
 | Logout and account deletion
 * =================================================================== */

$logout = $client->post('/api/v1/auth/logout');
Assert::same(200, $logout->status, 'Logout succeeds');
Assert::same(401, $client->get('/api/v1/usage')->status, 'Token rejected after logout');

$finalLogin = $client->post('/api/v1/auth/login', ['email' => $email, 'password' => $newPassword]);
$client->withToken((string) $finalLogin->data('token'));

$badConfirm = $client->post('/api/v1/auth/delete-account', [
    'password' => $newPassword,
    'confirm'  => 'yes',
]);
Assert::same(422, $badConfirm->status, 'Account deletion requires the exact confirmation word');

$deletedAccount = $client->post('/api/v1/auth/delete-account', [
    'password' => $newPassword,
    'confirm'  => 'DELETE',
]);
Assert::same(200, $deletedAccount->status, 'Account deletion succeeds');

$afterDeletion = $client->post('/api/v1/auth/login', ['email' => $email, 'password' => $newPassword]);
Assert::same(401, $afterDeletion->status, 'Deleted account can no longer sign in');
