<?php

declare(strict_types=1);

namespace Haryanvi\Core;

/**
 * Thin PDO wrapper.
 *
 * Every query in this project goes through here with bound parameters —
 * there is no string interpolation of user input into SQL anywhere.
 */
final class Database
{
    private static ?\PDO $pdo = null;

    private function __construct()
    {
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $host    = Config::getString('db.host', 'localhost');
        $port    = Config::getInt('db.port', 3306);
        $name    = Config::getString('db.name');
        $user    = Config::getString('db.user');
        $pass    = Config::getString('db.pass');
        $charset = Config::getString('db.charset', 'utf8mb4');
        $socket  = Config::get('db.socket');

        $dsn = is_string($socket) && $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $name, $charset)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                // Real prepared statements, not client-side emulation.
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (\PDOException $e) {
            // The driver message can contain credentials — log it, do not expose it.
            Logger::error('Database connection failed', ['message' => $e->getMessage()]);

            throw new ApiException(
                'DATABASE_UNAVAILABLE',
                'The database is currently unavailable. Please try again later.',
                503,
                [],
                $e
            );
        }

        return self::$pdo;
    }

    /**
     * Inject an existing connection (used by the test harness).
     */
    public static function setConnection(\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        try {
            $statement = self::pdo()->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (\PDOException $e) {
            Logger::error('Query failed', [
                'sqlstate' => $e->getCode(),
                // The SQL template is safe to log; bound values are not included.
                'sql'      => preg_replace('/\s+/', ' ', substr($sql, 0, 400)),
                'message'  => $e->getMessage(),
            ]);

            throw new ApiException(
                'DATABASE_ERROR',
                'A database error prevented this request from completing.',
                500,
                [],
                $e
            );
        }
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::run($sql, $params)->fetchAll();

        return $rows;
    }

    /** @param array<string|int,mixed> $params */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string|int,mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run a closure inside a transaction, rolling back on any throwable.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();

        // Nested calls join the outer transaction instead of failing.
        if ($pdo->inTransaction()) {
            return $work();
        }

        $pdo->beginTransaction();

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function isConnected(): bool
    {
        try {
            self::pdo()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
