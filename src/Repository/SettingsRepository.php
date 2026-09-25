<?php

declare(strict_types=1);

namespace TgJobParser\Repository;

use TgJobParser\Database\Database;

/** Ключ-значение (JSON) для настроек, которые пользователь меняет из UI. */
final class SettingsRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    public function set(string $name, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->transaction(function (Database $db) use ($name, $json): void {
            if ($db->scalar('SELECT 1 FROM settings WHERE name = ?', [$name]) !== null) {
                $db->update('settings', ['value' => $json], ['name' => $name]);
            } else {
                $db->insert('settings', ['name' => $name, 'value' => $json]);
            }
        });
        $this->cache = null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->all('SELECT name, value FROM settings') as $row) {
                $this->cache[(string) $row['name']] = json_decode((string) $row['value'], true);
            }
        }

        return $this->cache;
    }
}
