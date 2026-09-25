<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Application\SourceService;
use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;
use TgJobParser\Kernel\Config;

/** Подключает каналы из ТЗ (раздел 10). Повторный запуск безопасен. */
final class SeedCommand implements CommandInterface
{
    public function __construct(private readonly SourceService $service, private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'Подключить рекомендуемые каналы из ТЗ';
    }

    public function run(Input $input, Output $output): int
    {
        foreach ($this->config->array('seed_sources') as $handle) {
            try {
                $this->service->add((string) $handle);
                $output->line("+ {$handle}");
            } catch (\InvalidArgumentException $e) {
                $output->line("= {$handle} ({$e->getMessage()})");
            }
        }

        return 0;
    }
}
