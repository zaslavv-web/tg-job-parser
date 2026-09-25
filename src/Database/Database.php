<?php

declare(strict_types=1);

namespace TgJobParser\Database;

use PDO;
use PDOStatement;
use Throwable;

/** Тонкая обёртка над PDO: подготовленные запросы, транзакции, диалект-хелперы. */
final class Database
{
    private int $transactionDepth = 0;

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public static function connect(string $dsn, ?string $user = null, ?string $password = null): self
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            $file = substr($dsn, 7);
            if ($file !== ':memory:' && !is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
        }

        return new self(new PDO($dsn, $user, $password));
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Колонка первичного ключа с автоинкрементом в синтаксисе текущей СУБД. */
    public function idColumn(): string
    {
        return match ($this->driver()) {
            'mysql' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'pgsql' => 'SERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        // Типизированная привязка: иначе SQLite сравнивает INTEGER с TEXT '0' и фильтры по числам молча ломаются
        foreach ($params as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':'),
                is_bool($value) ? (int) $value : $value,
                match (true) {
                    is_int($value), is_bool($value) => PDO::PARAM_INT,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                },
            );
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );
        $this->run($sql, $row);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $row, array $where): int
    {
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :set_{$c}", array_keys($row)));
        $cond = implode(' AND ', array_map(static fn (string $c): string => "{$c} = :where_{$c}", array_keys($where)));
        $params = [];
        foreach ($row as $k => $v) {
            $params['set_' . $k] = $v;
        }
        foreach ($where as $k => $v) {
            $params['where_' . $k] = $v;
        }

        return $this->run("UPDATE {$table} SET {$set} WHERE {$cond}", $params)->rowCount();
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * Вложенные вызовы допустимы: реальная транзакция — только внешняя.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->transactionDepth++;
        try {
            $result = $callback($this);
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
