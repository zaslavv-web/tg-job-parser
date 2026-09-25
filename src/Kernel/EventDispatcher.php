<?php

declare(strict_types=1);

namespace TgJobParser\Kernel;

use Throwable;

/**
 * Синхронная шина событий. Ошибка одного подписчика изолирована:
 * пишется в лог, остальные подписчики и основной процесс продолжают работу.
 */
final class EventDispatcher
{
    /** @var array<string, list<callable(array<string, mixed>): void>> */
    private array $listeners = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** @param callable(array<string, mixed>): void $listener */
    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (Throwable $e) {
                $this->logger->error('Ошибка подписчика события', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                    'at' => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }
    }

    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }
}
