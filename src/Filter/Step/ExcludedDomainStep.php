<?php

declare(strict_types=1);

namespace TgJobParser\Filter\Step;

use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Filter\StepResult;
use TgJobParser\Filter\VacancyContext;

/**
 * Шаг 5: домен-исключение (ТЗ 2.4). action=reject — отклонить, action=penalty — только штраф в скоринге.
 * flag — имя флага профиля, который снимает отклонение (напр. allow_gambling).
 */
final class ExcludedDomainStep implements FilterStepInterface
{
    public function process(VacancyContext $context): StepResult
    {
        foreach ($context->matches('excluded_domains') as $term) {
            $flag = $term->meta('flag');
            if (is_string($flag) && $context->profile->flag($flag)) {
                continue;
            }
            if ($term->meta('action', 'reject') === 'reject') {
                return StepResult::reject('Домен-исключение: ' . $term->label);
            }
        }

        return StepResult::pass();
    }
}
