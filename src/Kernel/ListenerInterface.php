<?php

declare(strict_types=1);

namespace TgJobParser\Kernel;

/** Подписчик доменного события (регистрируется в config/modules.php → listeners). */
interface ListenerInterface
{
    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void;
}
