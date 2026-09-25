<?php

declare(strict_types=1);

namespace TgJobParser\Scoring\Rule;

use TgJobParser\Filter\VacancyContext;

/** Баллы за любое из ключевых слов: ['type' => 'keywords', 'patterns' => [...]]. */
final class KeywordRule extends AbstractRule
{
    protected function match(VacancyContext $context): ?string
    {
        return $context->matchesPatterns((array) ($this->definition['patterns'] ?? []), $this->scope);
    }
}
