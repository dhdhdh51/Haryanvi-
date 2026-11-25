<?php

declare(strict_types=1);

use Haryanvi\Core\Validator;
use Haryanvi\Tests\Assert;

/**
 * The validator is the API's trust boundary, so these tests focus on what it
 * must reject rather than what it accepts.
 */

Assert::startTest('Validator');

/* ---- Strings ---- */
$v = new Validator(['name' => '  Ramesh  ']);
Assert::same('Ramesh', $v->string('name'), 'Leading/trailing whitespace trimmed');
Assert::false($v->fails(), 'Valid string produces no errors');

$v = new Validator([]);
$v->string('name');
Assert::true($v->fails(), 'Missing required string is an error');
Assert::contains('required', $v->errors()['name'], 'Missing field error mentions requirement');

$v = new Validator(['bio' => str_repeat('अ', 300)]);
$v->string('bio', true, 0, 255);
Assert::true($v->fails(), 'Over-length string rejected using character count, not bytes');

$v = new Validator(['text' => "line1\r\nline2"]);
Assert::same("line1\nline2", $v->string('text'), 'CRLF normalised to LF');

$v = new Validator(['text' => "clean\x00\x07text"]);
Assert::same('cleantext', $v->string('text'), 'Control characters stripped');

$v = new Validator(['opt' => '']);
Assert::same('', $v->string('opt', false), 'Optional empty string allowed');
Assert::false($v->fails(), 'Optional empty string produces no error');

/* ---- Email ---- */
$v = new Validator(['email' => 'User@Example.COM']);
Assert::same('user@example.com', $v->email('email'), 'Email lower-cased');

$v = new Validator(['email' => 'not-an-email']);
$v->email('email');
Assert::true($v->fails(), 'Malformed email rejected');

/* ---- Password ---- */
$v = new Validator(['password' => 'short']);
$v->password('password', 8);
Assert::true($v->fails(), 'Too-short password rejected');

$v = new Validator(['password' => '12345678901']);
$v->password('password', 8);
Assert::true($v->fails(), 'All-digit password rejected');

$v = new Validator(['password' => 'correct horse battery']);
Assert::same('correct horse battery', $v->password('password', 8), 'Long passphrase accepted unmodified');
Assert::false($v->fails(), 'Passphrase produces no errors');

/* ---- Integers ---- */
$v = new Validator(['n' => '42']);
Assert::same(42, $v->int('n'), 'Numeric string cast to int');

$v = new Validator(['n' => '500']);
$v->int('n', true, 1, 100);
Assert::true($v->fails(), 'Out-of-range int rejected');

$v = new Validator(['n' => 'abc']);
$v->int('n');
Assert::true($v->fails(), 'Non-numeric int rejected');

/* ---- Booleans ---- */
$v = new Validator(['a' => true, 'b' => '1', 'c' => 'yes', 'd' => 0, 'e' => 'false']);
Assert::true($v->bool('a'), 'Native true');
Assert::true($v->bool('b'), 'String "1" is true');
Assert::true($v->bool('c'), 'String "yes" is true');
Assert::false($v->bool('d'), 'Zero is false');
Assert::false($v->bool('e'), 'String "false" is false');
Assert::true($v->bool('missing', true), 'Default used for absent key');

/* ---- Enums ---- */
$v = new Validator(['script' => 'ROMAN']);
Assert::same('roman', $v->enum('script', ['devanagari', 'roman']), 'Enum match is case-insensitive');

$v = new Validator(['script' => 'gurmukhi']);
$v->enum('script', ['devanagari', 'roman']);
Assert::true($v->fails(), 'Value outside the enum rejected');
Assert::contains('devanagari', $v->errors()['script'], 'Enum error lists the allowed values');

/* ---- URLs ---- */
$v = new Validator(['u' => 'http://insecure.example']);
$v->url('u', true, true);
Assert::true($v->fails(), 'Plain HTTP rejected when HTTPS is required');

$v = new Validator(['u' => 'https://secure.example/path']);
Assert::same('https://secure.example/path', $v->url('u'), 'HTTPS URL accepted');

/* ---- JSON objects ---- */
$v = new Validator(['h' => '{"X-Test":"1"}']);
Assert::same(['X-Test' => '1'], $v->jsonObject('h'), 'JSON string decoded to an array');

$v = new Validator(['h' => '{broken']);
$v->jsonObject('h');
Assert::true($v->fails(), 'Broken JSON rejected');

/* ---- assert() collects every error at once ---- */
$v = new Validator([]);
$v->string('a');
$v->string('b');
Assert::throws(
    static fn (): mixed => $v->assert(),
    'assert() raises a 422 for collected errors',
    'VALIDATION_FAILED'
);
Assert::same(2, count($v->errors()), 'All field errors reported together');
