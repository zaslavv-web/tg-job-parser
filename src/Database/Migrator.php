<?php

declare(strict_types=1);

namespace TgJobParser\Database;

use RuntimeException;

/**
 * Версионированные миграции: migrations/NNN_name.php возвращает callable(Database): void.
 * Схема меняется только добавлением нового файла — применённые миграции не редактируются.
 * Каждая миграция идёт в своей транзакции: упавшая не оставляет базу в полусостоянии.
 */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    /** @return list<string> имена применённых сейчас миграций */
    public function migrate(): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(191) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $applied = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $done = [];
        foreach ($this->pending($applied) as $name => $file) {
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException("Миграция {$name} должна возвращать callable(Database)");
            }
            $this->db->transaction(function (Database $db) use ($migration, $name): void {
                $migration($db);
                $db->insert('schema_migrations', ['name' => $name, 'applied_at' => date('c')]);
            });
            $done[] = $name;
        }

        return $done;
    }

    /** @return list<string> */
    public function status(): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(191) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $applied = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');

        return array_keys($this->pending($applied));
    }

    /**
     * @param list<string> $applied
     * @return array<string, string>
     */
    private function pending(array $applied): array
    {
        $files = glob($this->directory . '/*.php') ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $applied, true)) {
                $pending[$name] = $file;
            }
        }

        return $pending;
    }
}
