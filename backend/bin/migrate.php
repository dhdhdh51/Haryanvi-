<?php
/**
 * Database installer / migrator.
 *
 *   php bin/migrate.php --install    create the schema and load reference data
 *   php bin/migrate.php              apply any pending migrations
 *   php bin/migrate.php --status     show what is applied and what is pending
 *
 * Migrations are plain .sql files in database/migrations/, applied in
 * filename order and recorded in `schema_migrations` so they run once.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Haryanvi\Core\Database;

$arguments = array_slice($argv, 1);
$install   = in_array('--install', $arguments, true);
$status    = in_array('--status', $arguments, true);

$databaseDir   = HY_BASE_PATH . '/database';
$migrationsDir = $databaseDir . '/migrations';

function runSqlFile(string $path): int
{
    $sql = file_get_contents($path);

    if ($sql === false) {
        throw new RuntimeException('Could not read ' . $path);
    }

    // The schema files are authored as a single script; MySQL cannot execute
    // multiple statements through a prepared statement, so they are run via
    // exec() on the raw connection.
    $pdo = Database::pdo();
    $pdo->exec($sql);

    return 1;
}

function statementCount(string $path): int
{
    $sql = (string) file_get_contents($path);

    return max(1, substr_count($sql, ';'));
}

try {
    echo "\nHaryanvi AI — database setup\n";
    echo str_repeat('-', 46) . "\n";

    if (!Database::isConnected()) {
        fwrite(STDERR, "Could not connect to the database. Check config/config.php.\n");
        exit(1);
    }

    echo "Connected to database: " . \Haryanvi\Core\Config::getString('db.name') . "\n\n";

    if ($install) {
        echo "Creating schema from database/schema.sql ...\n";
        runSqlFile($databaseDir . '/schema.sql');
        echo "  done (" . statementCount($databaseDir . '/schema.sql') . " statements)\n";

        $alreadySeeded = (int) Database::fetchValue('SELECT COUNT(*) FROM dialects') > 0;

        if ($alreadySeeded) {
            echo "Reference data already present — skipping seed.\n";
        } else {
            echo "Loading reference data from database/seed.sql ...\n";
            runSqlFile($databaseDir . '/seed.sql');
            echo "  done\n";
        }

        echo "\nInstall complete.\n";
        echo "Next steps:\n";
        echo "  1. php bin/generate-key.php   (then paste the key into config/config.php)\n";
        echo "  2. php bin/create-admin.php   (replace the default admin account)\n";
        echo "  3. Add an AI provider in the admin dashboard, or:\n";
        echo "     php bin/provider.php add --name=\"OpenAI\" --driver=openai_compatible \\\n";
        echo "        --model=gpt-4o-mini --base-url=https://api.openai.com/v1 --key=sk-...\n\n";
        exit(0);
    }

    // Ensure the bookkeeping table exists even on a hand-loaded database.
    Database::execute(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version    VARCHAR(60) NOT NULL,
            applied_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $applied = array_map(
        static fn (array $row): string => (string) $row['version'],
        Database::fetchAll('SELECT version FROM schema_migrations ORDER BY version')
    );

    $files = is_dir($migrationsDir) ? (glob($migrationsDir . '/*.sql') ?: []) : [];
    sort($files);

    $pending = [];
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (!in_array($version, $applied, true)) {
            $pending[$version] = $file;
        }
    }

    if ($status) {
        echo "Applied migrations (" . count($applied) . "):\n";
        foreach ($applied as $version) {
            echo "  [x] " . $version . "\n";
        }
        echo "\nPending migrations (" . count($pending) . "):\n";
        foreach (array_keys($pending) as $version) {
            echo "  [ ] " . $version . "\n";
        }
        echo "\n";
        exit(0);
    }

    if ($pending === []) {
        echo "Database is up to date — nothing to apply.\n\n";
        exit(0);
    }

    foreach ($pending as $version => $file) {
        echo "Applying " . $version . " ... ";

        Database::transaction(static function () use ($file, $version): void {
            runSqlFile($file);
            Database::execute(
                'INSERT INTO schema_migrations (version) VALUES (:version)',
                ['version' => $version]
            );
        });

        echo "ok\n";
    }

    echo "\nApplied " . count($pending) . " migration(s).\n\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nMigration failed: " . $e->getMessage() . "\n");
    exit(1);
}
