<?php

declare(strict_types=1);

namespace TgJobParser\Filter;

/**
 * Шаг цепочки фильтров (ТЗ 3.3). Шаг читает контекст, может дописать в него атрибуты/причины
 * и возвращает решение. Шаги не знают друг о друге — порядок задаётся в config/pipeline.php.
 */
interface FilterStepInterface
{
    public function process(VacancyContext $context): StepResult;
}
