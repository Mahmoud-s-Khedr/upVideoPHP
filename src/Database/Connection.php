<?php

declare(strict_types=1);

namespace VideoSystem\Database;

use PDO;
use PDOStatement;
use VideoSystem\Config\Config;

/**
 * Lightweight PDO wrapper.
 *
 * All queries use prepared statements. Never interpolate user input into SQL.
 */
final class Connection
{
    private static ?PDO $pdo = null;

    /**
     * Returns the shared PDO instance (lazy singleton).
     */
    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::dbHost(),
                Config::dbPort(),
                Config::dbName()
            );

            self::$pdo = new PDO($dsn, Config::dbUser(), Config::dbPass(), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$pdo;
    }

    /**
     * Prepare and execute a statement, returning the PDOStatement.
     *
     * Integer values in $params are bound with PDO::PARAM_INT so LIMIT/OFFSET
     * and other numeric parameters are sent with the correct type.
     *
     * @param array<string|int, mixed> $params
     */
    public static function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            // PDO bindValue accepts both named (:key) and positional (1-based int) placeholders
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $value === null ? PDO::PARAM_NULL : $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * Fetch a single row, or null if no rows matched.
     *
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $result = self::execute($sql, $params)->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Fetch all rows.
     *
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::execute($sql, $params)->fetchAll();
    }

    /**
     * Returns the last insert ID as an integer.
     */
    public static function lastInsertId(): int
    {
        return (int) self::get()->lastInsertId();
    }

    /**
     * Ping the database and return true if reachable.
     */
    public static function ping(): bool
    {
        try {
            self::get()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reset the singleton (useful in tests).
     */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
