<?php

declare(strict_types=1);

namespace TgJobParser\Filter\Step;

use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Filter\StepResult;
use TgJobParser\Filter\VacancyContext;
use TgJobParser\Model\Post;
use TgJobParser\Scoring\Scorer;

/** Шаг 6: итоговый скоринг 0–100 и распределение по статусам по порогам профиля. */
final class ScoringStep implements FilterStepInterface
{
    public function __construct(private readonly Scorer $scorer)
    {
    }

    public function process(VacancyContext $context): StepResult
    {
        $score = $this->scorer->score($context);
        $min = $context->profile->threshold('min_score', 40);
        $recommended = $context->profile->threshold('recommended', 70);

        if ($score < $min) {
            return StepResult::reject("Низкий скоринг: {$score} < {$min}", $score);
        }

        return StepResult::accept($score >= $recommended ? Post::STATUS_RECOMMENDED : Post::STATUS_MAYBE, $score);
    }
}
