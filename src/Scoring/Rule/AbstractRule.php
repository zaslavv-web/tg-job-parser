<?php

declare(strict_types=1);

namespace TgJobParser\Scoring\Rule;

use TgJobParser\Filter\VacancyContext;
use TgJobParser\Scoring\ScoreHit;
use TgJobParser\Scoring\ScoringRuleInterface;

/** Общее для правил: баллы, метка с {match}, область (title/text), условия-исключения unless. */
abstract class AbstractRule implements ScoringRuleInterface
{
    protected readonly int $points;
    protected readonly string $label;
    protected readonly string $scope;

    /** @param array<string, mixed> $definition */
    public function __construct(protected readonly array $definition)
    {
        $this->points = (int) ($definition['points'] ?? 0);
        $this->label = (string) ($definition['label'] ?? ($definition['id'] ?? 'rule'));
        $this->scope = (string) ($definition['scope'] ?? 'text');
    }

    public function evaluate(VacancyContext $context): ?ScoreHit
    {
        if ($this->isSuppressed($context)) {
            return null;
        }
        $match = $this->match($context);

        return $match === null ? null : new ScoreHit($this->points, str_replace('{match}', $match, $this->label));
    }

    /** Совпадение (для подстановки в метку) или null. */
    abstract protected function match(VacancyContext $context): ?string;

    private function isSuppressed(VacancyContext $context): bool
    {
        $unlessList = $this->definition['unless_list'] ?? null;
        if (is_string($unlessList) && $context->has($unlessList)) {
            return true;
        }
        $unless = (array) ($this->definition['unless'] ?? []);

        return $unless !== [] && $context->matchesPatterns($unless, $this->scope) !== null;
    }
}
