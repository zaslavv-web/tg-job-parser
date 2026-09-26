<?php

declare(strict_types=1);

/*
 * Единая точка входа (она же — главный файл собранного исполняемого файла).
 *   без аргументов       → десктоп-режим: локальный сервер + браузер
 *   --no-browser         → то же, без открытия браузера
 *   --selftest           → самопроверка сборки
 *   <команда> [опции]    → CLI: parse, cron, rescore, sources, letter, health…
 *   --data=<каталог>     → где хранить БД/логи/.env (иначе — каталог данных ОС)
 */

require __DIR__ . '/bootstrap.php';

use TgJobParser\Cli\Console;
use TgJobParser\Desktop\DataDir;
use TgJobParser\Desktop\Launcher;
use TgJobParser\Desktop\SelfTest;
use TgJobParser\Kernel\App;

$args = array_slice($argv, 1);
$dataOption = null;
foreach ($args as $i => $arg) {
    if (str_starts_with($arg, '--data=')) {
        $dataOption = substr($arg, 7);
        unset($args[$i]);
    }
}
$args = array_values($args);

if (in_array('--selftest', $args, true)) {
    exit(SelfTest::run(__DIR__));
}

$runningPhar = class_exists(Phar::class) ? Phar::running(false) : '';
$executable = PHP_SAPI === 'micro' ? PHP_BINARY : ($runningPhar !== '' ? $runningPhar : __FILE__);

try {
    $dataDir = DataDir::resolve($dataOption, $executable);
    $command = $args[0] ?? null;
    if ($command === null || str_starts_with($command, '--')) {
        exit((new Launcher(__DIR__, $dataDir, __FILE__))->run(!in_array('--no-browser', $args, true)));
    }
    exit((new Console(App::boot(__DIR__, [], [], $dataDir)))->run(array_merge([$argv[0]], $args)));
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    if (PHP_OS_FAMILY === 'Windows' && PHP_SAPI === 'micro') {
        // Двойной клик: не даём окну закрыться, пока пользователь не прочитает ошибку
        fwrite(STDOUT, 'Нажмите Enter, чтобы закрыть…');
        fgets(STDIN);
    }
    exit(1);
}
