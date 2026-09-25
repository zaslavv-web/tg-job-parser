<?php

declare(strict_types=1);

namespace TgJobParser\Scoring;

use TgJobParser\Filter\VacancyContext;
use TgJobParser\Kernel\LoggerInterface;
use Throwable;

/** Суммирует баллы правил (ТЗ 3.4) и пишет объяснение в контекст. Упавшее правило пропускается. */
final class Scorer
{
    /** @param list<ScoringRuleInterface> $rules */
    public function __construct(private readonly array $rules, private readonly LoggerInterface $logger)
    {
    }

    public function score(VacancyContext $context): int
    {
        $total = 0;
        foreach ($this->rules as $rule) {
            try {
                $hit = $rule->evaluate($context);
            } catch (Throwable $e) {
                $this->logger->warning('Правило скоринга упало', ['rule' => $rule::class, 'error' => $e->getMessage()]);
                continue;
            }
            if ($hit === null || $hit->points === 0) {
                continue;
            }
            $total += $hit->points;
            $context->addReason($hit->label, $hit->points);
        }

        return max(0, min(100, $total));
    }
}
