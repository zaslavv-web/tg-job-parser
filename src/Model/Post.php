<?php

declare(strict_types=1);

namespace TgJobParser\Model;

/** Сохранённый пост с результатом классификации. */
final class Post
{
    public const STATUS_NEW = 'new';
    public const STATUS_NOT_VACANCY = 'not_vacancy';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_MAYBE = 'maybe';
    public const STATUS_RECOMMENDED = 'recommended';

    /** @param array<string, mixed> $row */
    public function __construct(public readonly array $row)
    {
    }

    public function id(): int
    {
        return (int) $this->row['id'];
    }

    public function sourceId(): int
    {
        return (int) $this->row['source_id'];
    }

    public function text(): string
    {
        return (string) ($this->row['text'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->row['manual_status'] ?: $this->row['status']);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->row[$key] ?? $default;
    }

    /** @return list<string> */
    public function links(): array
    {
        return self::decodeList($this->row['links'] ?? null);
    }

    /** @return list<array{label: string, url: string}> */
    public function buttons(): array
    {
        return self::decodeList($this->row['buttons'] ?? null);
    }

    /** @return list<array{label: string, points?: int}> */
    public function reasons(): array
    {
        return self::decodeList($this->row['reasons'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function trace(): array
    {
        return self::decodeList($this->row['trace'] ?? null);
    }

    public function isShortlisted(): bool
    {
        return in_array($this->status(), [self::STATUS_RECOMMENDED, self::STATUS_MAYBE], true);
    }

    public function toRawPost(): RawPost
    {
        return new RawPost(
            externalId: (string) $this->row['external_id'],
            text: $this->text(),
            url: $this->row['url'] ?? null,
            links: $this->links(),
            buttons: $this->buttons(),
        );
    }

    /** @return list<mixed> */
    private static function decodeList(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $value = json_decode($json, true);

        return is_array($value) ? array_values($value) : [];
    }
}
