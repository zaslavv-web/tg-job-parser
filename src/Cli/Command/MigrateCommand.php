<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;
use TgJobParser\Database\Migrator;

final class MigrateCommand implements CommandInterface
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Применить миграции БД (идемпотентно)';
    }

    public function run(Input $input, Output $output): int
    {
        $applied = $this->migrator->migrate();
        $output->line($applied ? 'Применены: ' . implode(', ', $applied) : 'Схема актуальна');

        return 0;
    }
}
