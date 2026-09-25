<?php

declare(strict_types=1);

namespace TgJobParser\Kernel;

/** Чтение окружения: переменные процесса важнее файла .env. */
final class Env
{
    /** @var array<string, string> */
    private array $fileValues = [];

    public function __construct(?string $dotenvPath = null)
    {
        if ($dotenvPath !== null && is_readable($dotenvPath)) {
            $this->fileValues = self::parse((string) file_get_contents($dotenvPath));
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        $value = $this->fileValues[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /** @return array<string, string> */
    public static function parse(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }

        return $values;
    }
}
