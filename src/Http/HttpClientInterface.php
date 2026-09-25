<?php

declare(strict_types=1);

namespace TgJobParser\Http;

/** Абстракция HTTP: драйверы источников и AI-клиенты не зависят от cURL и тестируются на фейке. */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @throws HttpException при сетевой ошибке (не при HTTP 4xx/5xx)
     */
    public function get(string $url, array $headers = []): HttpResponse;

    /**
     * @param array<mixed> $json
     * @param array<string, string> $headers
     * @throws HttpException
     */
    public function postJson(string $url, array $json, array $headers = []): HttpResponse;
}
