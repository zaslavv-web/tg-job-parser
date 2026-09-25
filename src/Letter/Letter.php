<?php

declare(strict_types=1);

namespace TgJobParser\Letter;

final class Letter
{
    public function __construct(
        public readonly string $text,
        public readonly string $language,
        public readonly string $mode,
        public readonly ?string $note = null,
    ) {
    }
}
