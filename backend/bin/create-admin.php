<?php
/**
 * Creates or updates a dashboard administrator.
 *
 *   php bin/create-admin.php
 *   php bin/create-admin.php --username=raj --email=raj@example.com --role=admin
 *
 * The password is read from the terminal without echoing where the platform
 * allows it, and only a hash is ever stored. Run this once after installing
 * and then delete the seeded `admin` account.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Haryanvi\Core\Database;
use Haryanvi\Services\PasswordHasher;

/** @return array<string,string> */
function parseArguments(array $argv): array
{
    $parsed = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z\-]+)=(.*)$/', $argument, $m) === 1) {
            $parsed[$m[1]] = $m[2];
        }
    }

    return $parsed;
}

function prompt(string $label, string $default = ''): string
{
    $suffix = $default === '' ? '' : ' [' . $default . ']';
    fwrite(STDOUT, $label . $suffix . ': ');

    $line = fgets(STDIN);
    $line = $line === false ? '' : trim($line);

    return $line === '' ? $default : $line;
}

function promptSecret(string $label): string
{
    fwrite(STDOUT, $label . ': ');

    // Turn off terminal echo where stty is available.
    $hasStty = @shell_exec('command -v stty 2>/dev/null') !== null;

    if ($hasStty) {
        @shell_exec('stty -echo 2>/dev/null');
    }

    $line = fgets(STDIN);

    if ($hasStty) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }

    return $line === false ? '' : trim($line);
}

try {
    $options = parseArguments($argv);

    echo "\nHaryanvi AI — create administrator\n";
    echo str_repeat('-', 46) . "\n";

    $username = $options['username'] ?? prompt('Username');
    $email    = strtolower($options['email'] ?? prompt('Email'));
    $fullName = $options['full-name'] ?? prompt('Full name', $username);
    $role     = $options['role'] ?? prompt('Role (super_admin|admin|moderator)', 'super_admin');

    if ($username === '' || !preg_match('/^[A-Za-z0-9._\-]{3,60}$/', $username)) {
        throw new RuntimeException('Username must be 3-60 characters: letters, digits, dot, dash or underscore.');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('A valid email address is required.');
    }

    if (!in_array($role, ['super_admin', 'admin', 'moderator'], true)) {
        throw new RuntimeException('Role must be super_admin, admin or moderator.');
    }

    $password = $options['password'] ?? promptSecret('Password (min 12 characters)');

    if (mb_strlen($password) < 12) {
        throw new RuntimeException('Admin passwords must be at least 12 characters.');
    }

    if (!isset($options['password'])) {
        $confirm = promptSecret('Confirm password');

        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException('The passwords did not match.');
        }
    }

    $hash = PasswordHasher::hash($password);

    $existing = Database::fetchOne(
        'SELECT id FROM admin_users WHERE username = :username OR email = :email LIMIT 1',
        ['username' => $username, 'email' => $email]
    );

    if ($existing !== null) {
        Database::execute(
            'UPDATE admin_users
             SET password_hash = :hash, email = :email, full_name = :name, role = :role,
                 is_active = 1, must_change_password = 0, failed_attempts = 0, locked_until = NULL
             WHERE id = :id',
            [
                'hash'  => $hash,
                'email' => $email,
                'name'  => $fullName,
                'role'  => $role,
                'id'    => (int) $existing['id'],
            ]
        );

        echo "\nUpdated existing administrator \"" . $username . "\".\n";
    } else {
        Database::execute(
            'INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active, must_change_password)
             VALUES (:username, :email, :hash, :name, :role, 1, 0)',
            [
                'username' => $username,
                'email'    => $email,
                'hash'     => $hash,
                'name'     => $fullName,
                'role'     => $role,
            ]
        );

        echo "\nCreated administrator \"" . $username . "\" (" . $role . ").\n";
    }

    echo "Password algorithm: " . PasswordHasher::algorithmName() . "\n";

    $defaultAdmin = Database::fetchOne(
        'SELECT id FROM admin_users WHERE username = \'admin\' AND must_change_password = 1'
    );

    if ($defaultAdmin !== null && $username !== 'admin') {
        echo "\nThe seeded default account \"admin\" still exists. Remove it now:\n";
        echo "  DELETE FROM admin_users WHERE username = 'admin';\n";
    }

    echo "\nSign in at https://your-domain/admin/\n\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFailed: " . $e->getMessage() . "\n\n");
    exit(1);
}
