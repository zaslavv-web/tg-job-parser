<?php

declare(strict_types=1);

namespace TgJobParser\Enricher;

use TgJobParser\Filter\VacancyContext;

/** Формат работы для карточки: Удалёнка / Гибрид / Офис / Релокация (можно несколько). */
final class WorkFormatEnricher implements EnricherInterface
{
    public function enrich(VacancyContext $context): void
    {
        $formats = [];
        if ($context->has('remote_markers')) {
            $formats[] = 'Удалёнка';
        }
        if ($context->has('hybrid_markers')) {
            $formats[] = 'Гибрид';
        }
        if ($context->has('relocation_markers')) {
            $formats[] = 'Релокация';
        }
        if ($formats === [] && $context->has('office_markers')) {
            $formats[] = 'Офис';
        }
        $context->set('work_format', $formats ? implode(' / ', $formats) : null);
    }
}
