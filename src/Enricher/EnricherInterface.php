<?php

declare(strict_types=1);

namespace TgJobParser\Enricher;

use TgJobParser\Filter\VacancyContext;

/** Извлекает атрибут поста (заголовок, язык, компанию…) в контекст до запуска фильтров. */
interface EnricherInterface
{
    public function enrich(VacancyContext $context): void;
}
