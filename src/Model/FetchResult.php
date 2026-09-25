<?php

declare(strict_types=1);

namespace TgJobParser\Model;

final class FetchResult
{
    /** @param list<RawPost> $posts */
    public function __construct(
        public readonly array $posts,
        public readonly ?string $cursor = null,
        public readonly ?string $title = null,
    ) {
    }
}
