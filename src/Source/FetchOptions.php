<?php

declare(strict_types=1);

namespace TgJobParser\Source;

use Closure;
use DateTimeImmutable;

final class FetchOptions
{
    /** @param (Closure(string): bool)|null $isKnown уже сохранён ли пост с таким externalId (чтобы не качать детали повторно) */
    public function __construct(
        public readonly int $maxPages = 5,
        public readonly int $maxPagesFirstRun = 3,
        public readonly ?DateTimeImmutable $notBefore = null,
        public readonly ?Closure $isKnown = null,
        public readonly int $maxDetailPages = 15,
    ) {
    }

    public function isKnown(string $externalId): bool
    {
        return $this->isKnown !== null && ($this->isKnown)($externalId);
    }
}
