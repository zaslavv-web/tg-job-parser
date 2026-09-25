<?php

declare(strict_types=1);

namespace TgJobParser\Http;

/**
 * cURL-клиент с таймаутами и повторами с экспоненциальной паузой на сетевых ошибках, 429 и 5xx.
 * Прокси берётся из окружения (HTTPS_PROXY), как у curl.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeout = 20,
        private readonly int $connectTimeout = 8,
        private readonly int $retries = 2,
        private readonly string $userAgent = 'tg-job-parser/1.0',
        private readonly int $backoffMs = 500,
    ) {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->send('GET', $url, null, $headers);
    }

    public function postJson(string $url, array $json, array $headers = []): HttpResponse
    {
        $headers['Content-Type'] = 'application/json';

        return $this->send('POST', $url, (string) json_encode($json, JSON_UNESCAPED_UNICODE), $headers);
    }

    /** @param array<string, string> $headers */
    private function send(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('Расширение ext-curl не установлено');
        }
        if (!preg_match('~^https?://~i', $url)) {
            throw new HttpException("Разрешены только http(s) URL: {$url}");
        }
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $this->once($method, $url, $body, $headers);
                if (($response->status === 429 || $response->status >= 500) && $attempt <= $this->retries) {
                    $this->pause($attempt, $response->headers['retry-after'] ?? null);
                    continue;
                }

                return $response;
            } catch (HttpException $e) {
                if ($attempt > $this->retries) {
                    throw $e;
                }
                $this->pause($attempt, null);
            }
        }
    }

    /** @param array<string, string> $headers */
    private function once(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_ENCODING => '',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new HttpException("HTTP {$method} {$url}: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return new HttpResponse($status, (string) $result, $responseHeaders, $effective);
    }

    private function pause(int $attempt, ?string $retryAfter): void
    {
        $ms = $retryAfter !== null && ctype_digit($retryAfter)
            ? min(10_000, (int) $retryAfter * 1000)
            : $this->backoffMs * (2 ** ($attempt - 1));
        usleep($ms * 1000);
    }
}
