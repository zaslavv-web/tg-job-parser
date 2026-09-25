<?php

declare(strict_types=1);

/*
 * Минимальный тест-раннер без зависимостей: php tests/run.php [фильтр]
 * Находит классы *Test в tests/, запускает публичные методы test*.
 */

require dirname(__DIR__) . '/bootstrap.php';

use TgJobParser\Tests\TestCase;

$filter = $argv[1] ?? null;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
$classes = [];
foreach ($files as $file) {
    if (!str_ends_with($file->getFilename(), 'Test.php')) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen(__DIR__) + 1, -4);
    $classes[] = 'TgJobParser\\Tests\\' . str_replace('/', '\\', $relative);
}
sort($classes);

$passed = 0;
$failed = [];
$started = microtime(true);
foreach ($classes as $class) {
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = substr($class, strlen('TgJobParser\\Tests\\')) . '::' . $method;
        if ($filter !== null && !str_contains($name, $filter)) {
            continue;
        }
        /** @var TestCase $test */
        $test = new $class();
        try {
            $test->setUp();
            $test->{$method}();
            $passed++;
            echo '.';
        } catch (Throwable $e) {
            $failed[] = [$name, $e];
            echo 'F';
        } finally {
            $test->tearDown();
        }
    }
}
echo "\n\n";
foreach ($failed as [$name, $e]) {
    echo "✘ {$name}\n   " . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n\n";
}
printf("%d passed, %d failed (%.2fs)\n", $passed, count($failed), microtime(true) - $started);
exit($failed ? 1 : 0);
