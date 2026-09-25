<?php

declare(strict_types=1);

namespace TgJobParser\Filter\Step;

use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Filter\StepResult;
use TgJobParser\Filter\VacancyContext;

/**
 * Шаг 4: география (ТЗ 2.5).
 *  1. Чёрный список — отклонить (кроме терминов с allow_if_remote при наличии удалёнки).
 *  2. Удалёнка — ок.
 *  3. Релокация — ок, если страна из белого списка (или страна не указана).
 *  4. Гибрид — ок, если город из белого списка гибрида; иначе отклонить.
 *  5. Только офис — пропускаем: штрафы решает скоринг (ТЗ 3.4: «Офис Москва без удалёнки −25»).
 *  6. Формат не указан — пропускаем (не теряем вакансию), скоринг решит.
 */
final class GeographyStep implements FilterStepInterface
{
    public function process(VacancyContext $context): StepResult
    {
        $remote = $context->isRemote();

        foreach ($context->matches('blocked_locations') as $term) {
            if ($remote && $term->meta('allow_if_remote', false)) {
                continue;
            }

            return StepResult::reject('География: ' . $term->label);
        }

        if ($remote) {
            return StepResult::pass('Удалёнка');
        }

        $relocation = array_filter(
            $context->matches('allowed_relocation'),
            static fn ($t): bool => !$t->meta('requires_remote', false),
        );
        $cities = $context->matches('allowed_hybrid_cities');

        if ($context->has('relocation_markers')) {
            if ($relocation !== [] || $cities !== []) {
                return StepResult::pass('Релокация: ' . ($relocation ? reset($relocation)->label : $cities[0]->label));
            }
            if ($this->mentionsDisallowedRequiresRemote($context)) {
                return StepResult::reject('География: релокация в страну, допустимую только на удалёнке');
            }

            return StepResult::pass('Релокация (страна не распознана)');
        }

        if ($context->has('hybrid_markers')) {
            if ($cities !== [] || $relocation !== []) {
                return StepResult::pass('Гибрид: ' . ($cities ? $cities[0]->label : reset($relocation)->label));
            }

            return StepResult::reject('География: гибрид вне допустимых городов');
        }

        if ($context->has('office_markers')) {
            return StepResult::pass('Офис' . ($cities ? ': ' . $cities[0]->label : ' — решит скоринг'));
        }

        return StepResult::pass('Формат работы не указан');
    }

    private function mentionsDisallowedRequiresRemote(VacancyContext $context): bool
    {
        foreach ($context->matches('allowed_relocation') as $term) {
            if ($term->meta('requires_remote', false)) {
                return true;
            }
        }

        return false;
    }
}
