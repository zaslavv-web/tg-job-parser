<?php

declare(strict_types=1);

namespace TgJobParser\Web;

use InvalidArgumentException;
use TgJobParser\Kernel\Container;
use TgJobParser\Kernel\LoggerInterface;
use Throwable;

/**
 * Маршруты из config/routes.php: [метод, шаблон пути, [Контроллер, метод]].
 * Все POST защищены CSRF. Ошибка контроллера → понятное сообщение, а не белый экран.
 */
final class Router
{
    /** @param list<array{0: string, 1: string, 2: array{0: class-string, 1: string}}> $routes */
    public function __construct(
        private readonly array $routes,
        private readonly Container $container,
        private readonly Session $session,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as [$method, $pattern, $handler]) {
            $regex = '~^' . preg_replace('~\{(\w+)\}~', '(?P<$1>[^/]+)', $pattern) . '$~';
            if ($method !== $request->method || !preg_match($regex, $request->path, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($method === 'POST' && !$this->session->validCsrf($request->string('_token'))) {
                return $this->fail($request, 'Сессия устарела, обновите страницу', 419);
            }
            try {
                [$class, $action] = $handler;

                return $this->container->get($class)->{$action}($request, ...array_values($params));
            } catch (InvalidArgumentException $e) {
                return $this->fail($request, $e->getMessage(), 422);
            } catch (Throwable $e) {
                $this->logger->error('Ошибка обработки запроса', ['path' => $request->path, 'error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);

                return $this->fail($request, 'Внутренняя ошибка: ' . $e->getMessage(), 500);
            }
        }

        return $request->wantsJson() ? Response::json(['error' => 'Not found'], 404) : new Response('Не найдено', 404);
    }

    private function fail(Request $request, string $message, int $status): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }
        $this->session->flash('error', $message);

        return Response::redirect('/');
    }
}
