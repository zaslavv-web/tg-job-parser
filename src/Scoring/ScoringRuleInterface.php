<?php

declare(strict_types=1);

namespace TgJobParser\Scoring;

use TgJobParser\Filter\VacancyContext;

/**
 * Правило скоринга. Создаётся фабрикой из строки config/pipeline.php → scoring_rules,
 * получая её целиком в $definition.
 */
interface ScoringRuleInterface
{
    /** @param array<string, mixed> $definition */
    public function __construct(array $definition);

    public function evaluate(VacancyContext $context): ?ScoreHit;
}
