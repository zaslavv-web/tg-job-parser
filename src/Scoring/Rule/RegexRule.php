<?php

declare(strict_types=1);

namespace TgJobParser\Scoring\Rule;

use TgJobParser\Filter\VacancyContext;

/** Регулярные выражения по исходному (не нормализованному) тексту — для сумм, валют, чисел. */
final class RegexRule extends AbstractRule
{
    protected function match(VacancyContext $context): ?string
    {
        $haystack = $this->scope === 'title' ? $context->title() : $context->text();
        foreach ((array) ($this->definition['patterns'] ?? []) as $regex) {
            if (@preg_match((string) $regex, $haystack, $m) === 1) {
                return trim($m[0]);
            }
        }

        return null;
    }
}
