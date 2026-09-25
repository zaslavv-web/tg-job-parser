<?php

declare(strict_types=1);

namespace TgJobParser\Listener;

use TgJobParser\Kernel\ListenerInterface;
use TgJobParser\Kernel\LoggerInterface;
use TgJobParser\Model\Source;

final class LogSourceFailureListener implements ListenerInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(array $payload): void
    {
        $source = $payload['source'] ?? null;
        $this->logger->warning('Источник не распарсился', [
            'source' => $source instanceof Source ? $source->kind . ':' . $source->handle : null,
            'error' => $payload['error'] ?? null,
        ]);
    }
}
