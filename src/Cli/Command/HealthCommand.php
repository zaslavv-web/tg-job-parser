<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Application\HealthCheck;
use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;

final class HealthCommand implements CommandInterface
{
    public function __construct(private readonly HealthCheck $health)
    {
    }

    public function name(): string
    {
        return 'health';
    }

    public function description(): string
    {
        return 'Самодиагностика: БД, миграции, модули, ключи';
    }

    public function run(Input $input, Output $output): int
    {
        $report = $this->health->run();
        foreach ($report['checks'] as $check) {
            $output->line(($check['ok'] ? '✔' : ($check['critical'] ? '✘' : '•')) . ' ' . Output::pad($check['name'], 22) . ' ' . $check['detail']);
        }

        return $report['ok'] ? 0 : 1;
    }
}
