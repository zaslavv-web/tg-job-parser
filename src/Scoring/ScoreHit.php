<?php

declare(strict_types=1);

namespace TgJobParser\Scoring;

final class ScoreHit
{
    public function __construct(public readonly int $points, public readonly string $label)
    {
    }
}
