<?php

declare(strict_types=1);

namespace TgJobParser\Scoring;

use InvalidArgumentException;

/** Создаёт правила из config/pipeline.php по типу → класс из config/modules.php. */
final class RuleFactory
{
    /** @param array<string, class-string<ScoringRuleInterface>> $types */
    public function __construct(private readonly array $types)
    {
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @return list<ScoringRuleInterface>
     */
    public function createAll(array $definitions): array
    {
        return array_map(fn (array $d): ScoringRuleInterface => $this->create($d), $definitions);
    }

    /** @param array<string, mixed> $definition */
    public function create(array $definition): ScoringRuleInterface
    {
        $type = (string) ($definition['type'] ?? '');
        $class = $this->types[$type] ?? null;
        if ($class === null || !is_subclass_of($class, ScoringRuleInterface::class)) {
            throw new InvalidArgumentException("Неизвестный тип правила скоринга: {$type}");
        }

        return new $class($definition);
    }
}
