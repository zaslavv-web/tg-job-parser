<?php

declare(strict_types=1);

namespace TgJobParser\Model;

/** Итог прогона поста через конвейер: статус, скор, объяснение. */
final class Classification
{
    /**
     * @param list<array{label: string, points?: int}> $reasons «Почему подходит» (и штрафы)
     * @param list<array<string, mixed>> $trace шаги конвейера — для отладки и лога отклонённых
     * @param array<string, mixed> $attributes title, company, work_format, language, vacancy_url…
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $score,
        public readonly ?string $rejectReason,
        public readonly array $reasons,
        public readonly array $trace,
        public readonly array $attributes,
    ) {
    }

    public function isVacancy(): bool
    {
        return $this->status !== Post::STATUS_NOT_VACANCY;
    }

    public function isShortlisted(): bool
    {
        return in_array($this->status, [Post::STATUS_RECOMMENDED, Post::STATUS_MAYBE], true);
    }
}
