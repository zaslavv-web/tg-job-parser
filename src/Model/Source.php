<?php

declare(strict_types=1);

namespace TgJobParser\Model;

/** Подключённый источник вакансий: Telegram-канал, RSS-лента, страница сайта… */
final class Source
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISABLED = 'disabled';

    /** @param array<string, mixed> $options настройки драйвера (селекторы, фильтры ссылок…) */
    public function __construct(
        public readonly ?int $id,
        public readonly string $kind,
        public readonly string $handle,
        public readonly ?string $url = null,
        public readonly ?string $title = null,
        public readonly array $options = [],
        public readonly bool $isActive = true,
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly ?string $lastError = null,
        public readonly ?string $lastPostId = null,
        public readonly ?string $lastParsedAt = null,
        public readonly ?string $createdAt = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            kind: (string) $row['kind'],
            handle: (string) $row['handle'],
            url: $row['url'] ?? null,
            title: $row['title'] ?? null,
            options: $row['options'] ? (array) json_decode((string) $row['options'], true) : [],
            isActive: (bool) $row['is_active'],
            status: (string) $row['status'],
            lastError: $row['last_error'] ?? null,
            lastPostId: $row['last_post_id'] ?? null,
            lastParsedAt: $row['last_parsed_at'] ?? null,
            createdAt: $row['created_at'] ?? null,
        );
    }

    public function displayName(): string
    {
        return $this->title ?: $this->handle;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
