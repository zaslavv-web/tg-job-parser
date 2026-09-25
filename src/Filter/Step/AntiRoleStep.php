<?php

declare(strict_types=1);

namespace TgJobParser\Filter\Step;

use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Filter\StepResult;
use TgJobParser\Filter\VacancyContext;
use TgJobParser\Profile\Matcher;

/**
 * Шаг 3: анти-должность (ТЗ 2.2) — проверяется по заголовку вакансии.
 * У термина может быть `unless`: «Project Manager» не анти, если в заголовке есть «продукт».
 */
final class AntiRoleStep implements FilterStepInterface
{
    public function process(VacancyContext $context): StepResult
    {
        $anti = self::findAntiRole($context);

        return $anti === null ? StepResult::pass() : StepResult::reject('Анти-должность: ' . $anti);
    }

    public static function findAntiRole(VacancyContext $context): ?string
    {
        $scope = $context->title() !== '' ? 'title' : 'text';
        $haystack = $scope === 'title' ? $context->normalizedTitle() : $context->normalizedText;
        foreach ($context->matches('anti_roles', $scope) as $term) {
            $unless = (array) $term->meta('unless', []);
            if ($unless !== [] && Matcher::matchesAny($unless, $haystack) !== null) {
                continue;
            }

            return $term->label;
        }

        return null;
    }
}
