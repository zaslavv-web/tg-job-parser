<?php

declare(strict_types=1);

namespace TgJobParser\Kernel;

use DateTimeImmutable;

/** Источник «сейчас» — подменяется в тестах. */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
