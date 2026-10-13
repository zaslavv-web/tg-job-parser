<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Application\SeedService;
use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;

/** Подключает каналы из ТЗ и рекомендуемые поиски LinkedIn. Повторный запуск безопасен. */
final class SeedCommand implements CommandInterface
{
    public function __construct(private readonly SeedService $seeds)
    {
    }

    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'Подключить рекомендуемые источники (каналы из ТЗ + поиски LinkedIn)';
    }

    public function run(Input $input, Output $output): int
    {
        $result = $this->seeds->seed();
        foreach ($result['added'] as $name) {
            $output->line("+ {$name}");
        }
        foreach ($result['skipped'] as $line) {
            $output->line("= {$line}");
        }

        return 0;
    }
}
