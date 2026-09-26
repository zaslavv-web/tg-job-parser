<?php

declare(strict_types=1);

namespace TgJobParser\Kernel;

/** Точечное обновление .env: меняет только переданные ключи, остальные строки и комментарии сохраняет. */
final class EnvFile
{
    /** @param array<string, string> $values */
    public static function update(string $path, array $values): void
    {
        $lines = is_file($path) ? (preg_split('/\R/', rtrim((string) file_get_contents($path))) ?: []) : [];
        $lines = array_values(array_filter($lines, static fn (string $l, int $i): bool => $l !== '' || $i > 0, ARRAY_FILTER_USE_BOTH));
        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException("Недопустимое имя переменной: {$key}");
            }
            $value = str_replace(["\r", "\n"], '', $value);
            $line = $key . '=' . (preg_match('/[\s#"\']/', $value) ? '"' . str_replace('"', '', $value) . '"' : $value);
            $found = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $existing)) {
                    $lines[$i] = $line;
                    $found = true;
                }
            }
            if (!$found) {
                $lines[] = $line;
            }
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $contents = rtrim(implode("\n", $lines)) . "\n";
        file_put_contents($path, $contents, LOCK_EX);
        @chmod($path, 0600);
    }
}
