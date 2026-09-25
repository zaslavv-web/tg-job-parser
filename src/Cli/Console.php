<?php

declare(strict_types=1);

namespace TgJobParser\Cli;

use TgJobParser\Kernel\App;
use Throwable;

final class Console
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    public function __construct(private readonly App $app)
    {
        foreach ($app->config->array('modules.commands') as $class) {
            $command = $app->get((string) $class);
            if ($command instanceof CommandInterface) {
                $this->commands[$command->name()] = $command;
            }
        }
    }

    /** @param list<string> $argv */
    public function run(array $argv, Output $output = new Output()): int
    {
        [$name, $input] = Input::fromArgv($argv);
        if ($name === null || $name === 'help' || !isset($this->commands[$name])) {
            if ($name !== null && $name !== 'help') {
                $output->error("Неизвестная команда: {$name}");
            }
            $output->line('Использование: php bin/console <команда> [--опции]');
            $output->line();
            foreach ($this->commands as $command) {
                $output->line(sprintf('  %-12s %s', $command->name(), $command->description()));
            }

            return $name === null || $name === 'help' ? 0 : 1;
        }
        try {
            return $this->commands[$name]->run($input, $output);
        } catch (Throwable $e) {
            $output->error('Ошибка: ' . $e->getMessage());

            return 1;
        }
    }
}
