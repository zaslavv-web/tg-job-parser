<?php

declare(strict_types=1);

namespace TgJobParser\Web;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . trim($path, '/'),
            $_GET,
            $_POST,
            array_map('strval', array_filter($_SERVER, 'is_scalar')),
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function wantsJson(): bool
    {
        return str_contains($this->server['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_starts_with($this->path, '/api/');
    }
}
