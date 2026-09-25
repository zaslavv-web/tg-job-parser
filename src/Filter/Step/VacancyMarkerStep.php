<?php

declare(strict_types=1);

namespace TgJobParser\Filter\Step;

use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Filter\StepResult;
use TgJobParser\Filter\VacancyContext;

/** Шаг 1: есть ли в посте маркеры вакансии. Источники-агрегаторы вакансий (job-сайты) шаг пропускают. */
final class VacancyMarkerStep implements FilterStepInterface
{
    public function process(VacancyContext $context): StepResult
    {
        if ($context->source->option('assume_vacancy', false)) {
            return StepResult::pass('Источник публикует только вакансии');
        }
        $markers = $context->matches('vacancy_markers');
        if ($markers === []) {
            return StepResult::notVacancy('Нет маркеров вакансии');
        }

        return StepResult::pass('Маркер: ' . $markers[0]->label);
    }
}
