<?php
declare(strict_types=1);

namespace App\Core;

use DateTime;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_PORT', '3306'),
                Env::get('DB_NAME', 'uels_db')
            );
            try {
                self::$pdo = new PDO($dsn, (string) Env::get('DB_USER', 'root'), (string) Env::get('DB_PASS', ''), [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                // Keep NOW()/CURDATE() in MySQL aligned with PHP's timezone (matters for overdue checks)
                $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
                self::$pdo->exec("SET time_zone = '" . $offset . "'");
            } catch (PDOException $e) {
                error_log('UELS DB connection failed: ' . $e->getMessage());
                throw new ApiException(
                    Env::bool('APP_DEBUG') ? 'Database connection failed: ' . $e->getMessage() : 'Database connection failed',
                    500
                );
            }
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute(array_values($params));
            return $stmt;
        } catch (PDOException $e) {
            throw self::translate($e);
        }
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $params = [])
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Runs INSERT/UPDATE/DELETE and returns the number of affected rows. */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** CALL a stored procedure; every UELS procedure ends with a single-row SELECT. */
    public static function call(string $procedure, array $params = []): ?array
    {
        $marks = implode(',', array_fill(0, count($params), '?'));
        $stmt = self::query("CALL {$procedure}({$marks})", $params);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        return $row === false ? null : $row;
    }

    /** Run $fn inside a DB transaction (commit on success, rollback on any error). */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Convert common database errors into friendly API errors. */
    private static function translate(PDOException $e): \Throwable
    {
        $state = (string) ($e->errorInfo[0] ?? '');
        $code  = (int) ($e->errorInfo[1] ?? 0);
        $msg   = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if ($state === '45000') {                       // SIGNAL from procedures/triggers
            return new ApiException($msg, 422);
        }
        if ($code === 1062) {
            return new ApiException('A record with the same unique value (ID number, email, asset tag or name) already exists.', 409);
        }
        if ($code === 1451) {
            return new ApiException('This record is still referenced by other records and cannot be removed.', 409);
        }
        if ($code === 1452) {
            return new ApiException('A related record (e.g. category or user) does not exist.', 422);
        }
        if (in_array($code, [3819, 4025, 4022], true)) {
            return new ApiException('A value or quantity violates a database constraint.', 422);
        }
        error_log('UELS SQL error: ' . $e->getMessage());
        return $e;
    }
}
