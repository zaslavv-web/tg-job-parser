<?php

declare(strict_types=1);

namespace TgJobParser\Model;

use DateTimeImmutable;

/** Пост/объявление в том виде, в каком его отдал драйвер источника. Общий формат для всех источников. */
final class RawPost
{
    /**
     * @param list<string> $links внешние ссылки из текста
     * @param list<array{label: string, url: string}> $buttons инлайн-кнопки
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $text,
        public readonly ?string $url = null,
        public readonly ?DateTimeImmutable $publishedAt = null,
        public readonly array $links = [],
        public readonly array $buttons = [],
    ) {
    }
}
