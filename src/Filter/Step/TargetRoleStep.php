<?php

declare(strict_types=1);

namespace TgJobParser\Filter\Step;

use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Filter\StepResult;
use TgJobParser\Filter\VacancyContext;

/** Шаг 2: должность из белого списка? Нет → проверяем анти-список, иначе (по флагу) отклоняем. */
final class TargetRoleStep implements FilterStepInterface
{
    public function process(VacancyContext $context): StepResult
    {
        $matches = $context->matches('target_roles', 'title') ?: $context->matches('target_roles');
        if ($matches !== []) {
            // Самая специфичная (длинная) метка — для карточки
            usort($matches, static fn ($a, $b): int => mb_strlen($b->label) <=> mb_strlen($a->label));
            $context->set('target_role', $matches[0]->label);

            return StepResult::pass('Целевая должность: ' . $matches[0]->label);
        }
        $anti = AntiRoleStep::findAntiRole($context);
        if ($anti !== null) {
            return StepResult::reject('Анти-должность: ' . $anti);
        }
        if ($context->profile->flag('require_target_role')) {
            return StepResult::reject('Должность не из белого списка' . ($context->title() ? ': ' . $context->title() : ''));
        }

        return StepResult::pass('Должность не распознана');
    }
}
