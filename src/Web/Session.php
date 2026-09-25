<?php

declare(strict_types=1);

namespace TgJobParser\Web;

/** Сессия: flash-сообщения и CSRF-токен. В тестах работает на массиве. */
class Session
{
    /** @var array<string, mixed> */
    protected array $data;

    public function __construct(bool $native = true)
    {
        if ($native && session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
        if ($native && isset($_SESSION)) {
            $this->data = &$_SESSION;
        } else {
            $this->data = [];
        }
    }

    public function csrfToken(): string
    {
        return $this->data['csrf'] ??= bin2hex(random_bytes(16));
    }

    public function validCsrf(string $token): bool
    {
        return $token !== '' && hash_equals($this->csrfToken(), $token);
    }

    public function flash(string $type, string $message): void
    {
        $this->data['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array
    {
        $flashes = $this->data['flash'] ?? [];
        unset($this->data['flash']);

        return $flashes;
    }
}
