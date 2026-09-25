<?php

declare(strict_types=1);

namespace TgJobParser\Application;

final class ParseReport
{
    public int $sourcesOk = 0;
    public int $sourcesFailed = 0;
    public int $postsNew = 0;
    public int $vacanciesFound = 0;
    public bool $skipped = false;
    public ?string $skipReason = null;

    /** @var array<string, string> источник → ошибка */
    public array $errors = [];

    /** @var list<int> id новых подходящих вакансий */
    public array $newShortlisted = [];

    public function summary(): string
    {
        if ($this->skipped) {
            return 'Пропущено: ' . $this->skipReason;
        }

        return sprintf(
            'Источников: %d ок, %d с ошибкой. Новых постов: %d, подходящих вакансий: %d.',
            $this->sourcesOk,
            $this->sourcesFailed,
            $this->postsNew,
            $this->vacanciesFound,
        );
    }
}
