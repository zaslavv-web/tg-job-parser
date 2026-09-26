<?php

declare(strict_types=1);

namespace TgJobParser\Desktop;

/**
 * Где хранить изменяемые данные (БД, логи, .env) в десктоп-режиме.
 * Порядок: --data=… → TGJP_DATA_DIR → «портативная» папка TgJobParser-data рядом с программой
 * → стандартный каталог данных приложений ОС.
 */
final class DataDir
{
    public const APP_NAME = 'TgJobParser';

    /** @param array<string, string> $env */
    public static function resolve(?string $explicit, ?string $executable, array $env = []): string
    {
        $env = $env ?: getenv();
        $candidates = [
            $explicit,
            $env['TGJP_DATA_DIR'] ?? null,
        ];
        if ($executable) {
            $portable = dirname($executable) . DIRECTORY_SEPARATOR . self::APP_NAME . '-data';
            if (is_dir($portable)) {
                $candidates[] = $portable;
            }
        }
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return self::ensure($candidate);
            }
        }

        return self::ensure(self::osDefault($env));
    }

    /** @param array<string, string> $env */
    public static function osDefault(array $env, string $os = PHP_OS_FAMILY): string
    {
        $home = $env['HOME'] ?? $env['USERPROFILE'] ?? sys_get_temp_dir();

        return match ($os) {
            'Windows' => ($env['LOCALAPPDATA'] ?? $env['APPDATA'] ?? $home . '\\AppData\\Local') . '\\' . self::APP_NAME,
            'Darwin' => $home . '/Library/Application Support/' . self::APP_NAME,
            default => ($env['XDG_DATA_HOME'] ?? $home . '/.local/share') . '/tg-job-parser',
        };
    }

    private static function ensure(string $dir): string
    {
        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать каталог данных: {$dir}");
        }

        return $dir;
    }
}
