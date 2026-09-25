<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use TgJobParser\Kernel\App;
use TgJobParser\Web\WebKernel;

// Встроенный сервер PHP: статику отдаём как есть
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

(new WebKernel(App::boot(dirname(__DIR__))))->handleGlobals();
