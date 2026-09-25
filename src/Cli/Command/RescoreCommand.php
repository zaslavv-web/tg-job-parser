<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Application\RescoreService;
use TgJobParser\Application\VacancyQuery;
use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;

final class RescoreCommand implements CommandInterface
{
    public function __construct(private readonly RescoreService $rescore)
    {
    }

    public function name(): string
    {
        return 'rescore';
    }

    public function description(): string
    {
        return 'Пересчитать сохранённые посты по текущим правилам [--period=today|week|all]';
    }

    public function run(Input $input, Output $output): int
    {
        $stats = $this->rescore->rescore(VacancyQuery::since((string) $input->option('period', 'all')));
        $output->line(sprintf('Пересчитано: %d, подходящих: %d, ошибок: %d', $stats['processed'], $stats['shortlisted'], $stats['failed']));

        return $stats['failed'] > 0 ? 1 : 0;
    }
}
