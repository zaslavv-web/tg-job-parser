<?php

declare(strict_types=1);

/*
 * Автозагрузка без обязательного composer: если есть vendor/ — берём его
 * (нужно для AI-режима Claude), иначе собственный PSR-4 загрузчик для src/.
 */
$root = __DIR__;

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($root): void {
    $map = ['TgJobParser\\Tests\\' => $root . '/tests/', 'TgJobParser\\' => $root . '/src/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "Нужен PHP 8.2+\n");
    exit(1);
}

mb_internal_encoding('UTF-8');
date_default_timezone_set(getenv('TZ') ?: 'Europe/Moscow');
