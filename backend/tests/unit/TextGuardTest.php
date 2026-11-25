<?php

declare(strict_types=1);

use Haryanvi\Services\TextGuard;
use Haryanvi\Tests\Assert;

/**
 * TextGuard is what keeps a shared link or an OTP from being "translated".
 * These tests cover the masking round trip and the detection of spans the
 * model dropped.
 */

Assert::startTest('TextGuard');

/* ---- URLs ---- */
$guard = TextGuard::protect('Check https://example.com/page?id=42 for details');
Assert::notContains('https://example.com', $guard->maskedText, 'URL is masked before the model sees it');
Assert::contains('{{1}}', $guard->maskedText, 'URL replaced with a numbered placeholder');
Assert::same(
    'उरै देख ले https://example.com/page?id=42',
    $guard->restore('उरै देख ले {{1}}'),
    'URL restored exactly after translation'
);

/* ---- Email ---- */
$guard = TextGuard::protect('Mail me at ram.kumar@example.co.in please');
Assert::notContains('ram.kumar@example.co.in', $guard->maskedText, 'Email address is masked');
Assert::contains('ram.kumar@example.co.in', $guard->restore($guard->maskedText), 'Email address round-trips');

/* ---- Phone numbers ---- */
$guard = TextGuard::protect('Call +91 98765 43210 now');
Assert::contains('+91 98765 43210', $guard->restore($guard->maskedText), 'Phone number round-trips unchanged');

/* ---- Handles and hashtags ---- */
$guard = TextGuard::protect('Tag @ramesh_91 with #HaryanviAI');
$restored = $guard->restore($guard->maskedText);
Assert::contains('@ramesh_91', $restored, 'Mention round-trips');
Assert::contains('#HaryanviAI', $restored, 'Hashtag round-trips');

/* ---- OTP / long digit runs ---- */
$guard = TextGuard::protect('Your OTP is 849302');
Assert::contains('849302', $guard->restore($guard->maskedText), 'Six-digit OTP round-trips');

/* ---- Emoji are reported, not masked ---- */
$guard = TextGuard::protect('बढ़िया काम 👍 भाई 🙏');
Assert::contains('👍', $guard->promptConstraints(), 'Emoji listed in the prompt constraints');
Assert::contains('👍', $guard->maskedText, 'Emoji left inline rather than masked');

/* ---- Short numbers are instructed, not masked ---- */
$guard = TextGuard::protect('मीटिंग 5 बजे सै');
Assert::contains('5', $guard->maskedText, 'Short number stays inline');
Assert::contains('Keep these numbers', $guard->promptConstraints(), 'Short number listed as a constraint');

/* ---- Missing placeholder detection ---- */
$guard = TextGuard::protect('Open https://example.com and https://other.example');
Assert::same(2, count($guard->map), 'Both URLs masked');
Assert::same([], $guard->missingPlaceholders($guard->maskedText), 'Nothing missing when all placeholders survive');
Assert::same([2], $guard->missingPlaceholders('देख ले {{1}}'), 'Dropped placeholder is reported');

/* ---- Text with nothing to protect ---- */
$guard = TextGuard::protect('के हाल सै');
Assert::false($guard->hasProtectedSpans(), 'Plain text has no protected spans');
Assert::same('', $guard->promptConstraints(), 'No constraints emitted for plain text');
Assert::same('के हाल सै', $guard->restore('के हाल सै'), 'Plain text restore is a no-op');

/* ---- Multiple categories together ---- */
$guard = TextGuard::protect('Hi @ravi, see https://x.example at 7 — OTP 123456 🙏');
Assert::greaterThan(2, count($guard->map), 'Several spans masked in one message');
$restored = $guard->restore($guard->maskedText);
Assert::contains('@ravi', $restored, 'Mention survives a multi-span message');
Assert::contains('https://x.example', $restored, 'URL survives a multi-span message');
Assert::contains('123456', $restored, 'OTP survives a multi-span message');
