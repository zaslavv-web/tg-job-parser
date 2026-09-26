<?php

declare(strict_types=1);

/*
 * Сборка приложения в один архив: php -d phar.readonly=0 build/build-phar.php [dist/tg-job-parser.phar]
 * Дальше архив склеивается с рантаймом static-php-cli (micro.sfx) в один исполняемый файл —
 * см. build/make-executable.sh и .github/workflows/release.yml.
 */

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Запустите с -d phar.readonly=0\n");
    exit(1);
}

$root = dirname(__DIR__);
$target = $argv[1] ?? $root . '/dist/tg-job-parser.phar';
@mkdir(dirname($target), 0775, true);
@unlink($target);

// Что входит в сборку. Тесты, CI, документация и .git — нет.
$include = ['app.php', 'bootstrap.php', 'composer.json', 'config', 'migrations', 'public', 'scripts', 'src', 'templates', 'vendor'];
$skip = '~/(tests?|Tests?|docs?|\.git|\.github|examples?)/~';

$phar = new Phar($target);
$phar->startBuffering();
$count = 0;
foreach ($include as $entry) {
    $path = $root . '/' . $entry;
    if (is_file($path)) {
        $phar->addFile($path, $entry);
        $count++;
        continue;
    }
    if (!is_dir($path)) {
        if ($entry === 'vendor') {
            fwrite(STDERR, "Внимание: нет vendor/ — AI-режим Claude в сборку не попадёт (composer install --no-dev)\n");
        }
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = $entry . '/' . substr($file->getPathname(), strlen($path) + 1);
        $relative = str_replace('\\', '/', $relative);
        if ($entry === 'vendor' && (preg_match($skip, '/' . $relative) || !preg_match('~\.(php|json|pem|crt)$~', $relative))) {
            continue;
        }
        $phar->addFile($file->getPathname(), $relative);
        $count++;
    }
}

// Стаб: запуск архива = запуск app.php (и через `php x.phar`, и внутри micro-исполняемого файла)
$phar->setStub(<<<'STUB'
<?php
Phar::mapPhar('tg-job-parser.phar');
require 'phar://tg-job-parser.phar/app.php';
__HALT_COMPILER();
STUB);
$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();

printf("Собрано: %s (%d файлов, %.1f МБ)\n", $target, $count, filesize($target) / 1048576);
