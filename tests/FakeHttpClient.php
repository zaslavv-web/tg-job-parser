<?php

declare(strict_types=1);

namespace TgJobParser\Tests;

use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Http\HttpException;
use TgJobParser\Http\HttpResponse;

/** HTTP-заглушка: URL (точно или по regex ~…~) → ответ или исключение. Пишет журнал запросов. */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, body: ?array}> */
    public array $requests = [];

    /** @param array<string, string|HttpResponse|\Throwable> $routes */
    public function __construct(private array $routes = [])
    {
    }

    public function on(string $url, string|HttpResponse|\Throwable $response): self
    {
        $this->routes[$url] = $response;

        return $this;
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'body' => null];

        return $this->respond($url);
    }

    public function postJson(string $url, array $json, array $headers = []): HttpResponse
    {
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'body' => $json];

        return $this->respond($url);
    }

    private function respond(string $url): HttpResponse
    {
        $match = $this->routes[$url] ?? null;
        if ($match === null) {
            foreach ($this->routes as $pattern => $response) {
                if (str_starts_with($pattern, '~') && preg_match($pattern, $url)) {
                    $match = $response;
                    break;
                }
            }
        }
        if ($match === null) {
            throw new HttpException("FakeHttpClient: нет маршрута для {$url}");
        }
        if ($match instanceof \Throwable) {
            throw $match;
        }

        return $match instanceof HttpResponse ? $match : new HttpResponse(200, $match, [], $url);
    }
}
