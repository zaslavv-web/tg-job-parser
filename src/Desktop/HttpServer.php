<?php

declare(strict_types=1);

namespace TgJobParser\Desktop;

use Closure;
use Throwable;
use TgJobParser\Web\Request;
use TgJobParser\Web\Response;

/**
 * Встроенный HTTP/1.1-сервер на чистом PHP для десктоп-режима: один процесс, одно соединение
 * за раз, только 127.0.0.1. Не зависит от SAPI (работает в php-cli и в static-php micro).
 */
final class HttpServer
{
    private const STATIC_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
    ];
    private const MAX_BODY = 2_000_000;

    /** @var resource|null */
    private $socket = null;
    private int $port = 0;
    private bool $stopping = false;

    /**
     * @param Closure(Request): Response $handler
     * @param string $publicDir каталог статики (может быть внутри phar)
     */
    public function __construct(private readonly Closure $handler, private readonly string $publicDir)
    {
    }

    /** Занимает первый свободный порт из списка (0 — любой свободный). */
    public function listen(array $ports): int
    {
        foreach ($ports as $port) {
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($socket !== false) {
                $this->socket = $socket;
                $name = (string) stream_socket_get_name($socket, false);
                $this->port = (int) substr($name, strrpos($name, ':') + 1);

                return $this->port;
            }
        }
        throw new \RuntimeException('Не удалось открыть порт на 127.0.0.1');
    }

    public function port(): int
    {
        return $this->port;
    }

    /** Цикл обработки; $tick вызывается примерно раз в секунду (расписание, фоновые задачи). */
    public function serve(?Closure $tick = null): void
    {
        while (!$this->stopping) {
            $read = [$this->socket];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 1);
            if ($ready > 0) {
                $client = @stream_socket_accept($this->socket, 0);
                if ($client !== false) {
                    $this->handleClient($client);
                }
            }
            if ($tick !== null) {
                try {
                    $tick();
                } catch (Throwable) {
                    // Сбой фоновой задачи не должен останавливать сервер
                }
            }
        }
        fclose($this->socket);
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /** @param resource $client */
    private function handleClient($client): void
    {
        stream_set_timeout($client, 10);
        $raw = '';
        while (!str_contains($raw, "\r\n\r\n") && strlen($raw) < 65536) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        if (preg_match('/\r\nContent-Length:\s*(\d+)/i', $raw, $m)) {
            $need = min((int) $m[1], self::MAX_BODY);
            $headerEnd = strpos($raw, "\r\n\r\n");
            while ($headerEnd !== false && strlen($raw) - $headerEnd - 4 < $need) {
                $chunk = fread($client, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }
        }
        @fwrite($client, $this->handleRaw($raw));
        fclose($client);
    }

    /** Разбор «сырого» запроса → «сырой» ответ. Отдельным методом — для тестов и самопроверки. */
    public function handleRaw(string $raw): string
    {
        try {
            [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
            $lines = explode("\r\n", $head);
            if (!preg_match('~^(GET|POST|HEAD) (\S+) HTTP/1\.[01]$~', (string) array_shift($lines), $m)) {
                return $this->serialize(new Response('Bad Request', 400, ['Content-Type' => 'text/plain']));
            }
            $headers = [];
            foreach ($lines as $line) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
            // Защита от DNS-rebinding: принимаем только запросы на локальный адрес
            $host = strtolower(preg_replace('/:\d+$/', '', $headers['host'] ?? ''));
            if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
                return $this->serialize(new Response('Forbidden', 403, ['Content-Type' => 'text/plain']));
            }
            $path = (string) parse_url($m[2], PHP_URL_PATH);
            parse_str((string) parse_url($m[2], PHP_URL_QUERY), $query);
            $post = [];
            if ($m[1] === 'POST' && str_contains($headers['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
                parse_str(substr($body, 0, self::MAX_BODY), $post);
            }

            if ($m[1] !== 'POST' && ($static = $this->staticFile($path)) !== null) {
                return $this->serialize($static, $m[1] === 'HEAD');
            }
            $server = ['HTTP_HOST' => $headers['host'] ?? '', 'HTTP_ACCEPT' => $headers['accept'] ?? '', 'HTTP_REFERER' => $headers['referer'] ?? ''];
            $request = new Request($m[1] === 'HEAD' ? 'GET' : $m[1], '/' . trim($path, '/'), $query, $post, $server);
            $response = ($this->handler)($request);
            if (($response->headers['X-App-Shutdown'] ?? '') === '1') {
                $this->stop();
            }

            return $this->serialize($response, $m[1] === 'HEAD');
        } catch (Throwable $e) {
            return $this->serialize(new Response('Internal error: ' . htmlspecialchars($e->getMessage()), 500));
        }
    }

    private function staticFile(string $path): ?Response
    {
        if (!preg_match('~^/assets/[\w.-]+\.(\w+)$~', $path, $m) || !isset(self::STATIC_TYPES[$m[1]])) {
            return null;
        }
        $file = $this->publicDir . $path;
        if (!is_file($file)) {
            return null;
        }

        return new Response((string) file_get_contents($file), 200, ['Content-Type' => self::STATIC_TYPES[$m[1]], 'Cache-Control' => 'max-age=300']);
    }

    private function serialize(Response $response, bool $headOnly = false): string
    {
        $reasons = [200 => 'OK', 303 => 'See Other', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 419 => 'Expired', 422 => 'Unprocessable Entity', 500 => 'Internal Server Error', 503 => 'Service Unavailable'];
        $headers = $response->headers + [
            'Content-Length' => (string) strlen($response->body),
            'Connection' => 'close',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];
        unset($headers['X-App-Shutdown']);
        $out = sprintf("HTTP/1.1 %d %s\r\n", $response->status, $reasons[$response->status] ?? 'OK');
        foreach ($headers as $name => $value) {
            $out .= $name . ': ' . str_replace(["\r", "\n"], '', $value) . "\r\n";
        }

        return $out . "\r\n" . ($headOnly ? '' : $response->body);
    }
}
