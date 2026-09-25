<?php

declare(strict_types=1);

namespace TgJobParser\Scoring\Rule;

use TgJobParser\Filter\VacancyContext;

/**
 * Баллы за совпадение со списком профиля: ['type' => 'list', 'list' => 'priority_domains', …].
 * scope: text | title | any (сначала заголовок, затем текст).
 */
final class ListMatchRule extends AbstractRule
{
    protected function match(VacancyContext $context): ?string
    {
        $list = (string) ($this->definition['list'] ?? '');
        $matches = $this->scope === 'any'
            ? ($context->matches($list, 'title') ?: $context->matches($list, 'text'))
            : $context->matches($list, $this->scope);
        if ($matches === []) {
            return null;
        }
        $labels = array_map(static fn ($t): string => $t->label, $matches);
        usort($labels, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return implode(', ', array_slice($labels, 0, (int) ($this->definition['max_labels'] ?? 2)));
    }
}
