<?php

declare(strict_types=1);

namespace TgJobParser\Tests;

use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Kernel\App;
use TgJobParser\Kernel\LoggerInterface;
use TgJobParser\Kernel\MemoryLogger;
use TgJobParser\Web\Session;

/** Приложение на SQLite в памяти, с фейковым HTTP и логгером в память. */
final class AppFactory
{
    /**
     * @param array<string, mixed> $overrides
     * @param array<string, \Closure> $services
     */
    public static function create(?FakeHttpClient $http = null, array $overrides = [], array $services = []): App
    {
        $http ??= new FakeHttpClient();
        $lock = sys_get_temp_dir() . '/tg-job-parser-test-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
        $app = App::boot(dirname(__DIR__), array_merge([
            'db.dsn' => 'sqlite::memory:',
            'paths.lock' => $lock,
            'paths.log' => sys_get_temp_dir() . '/tg-job-parser-test.log',
            'ai.claude.api_key' => null,
            'ai.openai.api_key' => null,
            'letters.mode' => 'template',
            'notify.telegram_bot_token' => null,
            'parsing.max_post_age_days' => 0,
            'web.password' => null,
        ], $overrides), array_merge([
            HttpClientInterface::class => static fn () => $http,
            LoggerInterface::class => static fn () => new MemoryLogger(),
            Session::class => static fn () => new Session(false),
        ], $services));
        $app->migrate();

        return $app;
    }
}
