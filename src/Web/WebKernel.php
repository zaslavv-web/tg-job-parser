<?php

declare(strict_types=1);

namespace TgJobParser\Web;

use TgJobParser\Database\Migrator;
use TgJobParser\Kernel\App;
use TgJobParser\Kernel\LoggerInterface;
use Throwable;

/** HTTP-вход: авторизация, автомиграции, маршрутизация, аварийная страница. */
final class WebKernel
{
    public function __construct(private readonly App $app)
    {
    }

    public function handleGlobals(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }

    public function handle(Request $request): Response
    {
        try {
            if (!$this->authorized($request)) {
                return new Response('Требуется авторизация', 401, ['WWW-Authenticate' => 'Basic realm="tg-job-parser"', 'Content-Type' => 'text/plain; charset=utf-8']);
            }
            // Идемпотентно и транзакционно: после обновления кода схема догоняется сама
            $this->app->get(Migrator::class)->migrate();
            $container = $this->app->container;
            $router = new Router(
                $this->app->config->array('routes'),
                $container,
                $container->get(Session::class),
                $container->get(LoggerInterface::class),
            );

            return $router->dispatch($request);
        } catch (Throwable $e) {
            try {
                $this->app->get(LoggerInterface::class)->error('Фатальная ошибка веб-запроса', ['error' => $e->getMessage()]);
            } catch (Throwable) {
            }

            return new Response(
                '<h1>Сервис временно недоступен</h1><p>' . htmlspecialchars($e->getMessage()) . '</p><p>Проверьте <code>php bin/console health</code>.</p>',
                500,
            );
        }
    }

    private function authorized(Request $request): bool
    {
        $password = (string) $this->app->config->get('web.password');
        if ($password === '') {
            return true;
        }

        return hash_equals($password, $request->server['PHP_AUTH_PW'] ?? '');
    }
}
