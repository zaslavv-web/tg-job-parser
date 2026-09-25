<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Application\ParseService;
use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;

final class ParseCommand implements CommandInterface
{
    public function __construct(private readonly ParseService $parser)
    {
    }

    public function name(): string
    {
        return 'parse';
    }

    public function description(): string
    {
        return 'Проверить все каналы сейчас [--source=ID]';
    }

    public function run(Input $input, Output $output): int
    {
        $source = $input->option('source');
        $report = $this->parser->run('cli', $source !== null ? (int) $source : null);
        $output->line($report->summary());
        foreach ($report->errors as $name => $error) {
            $output->line("  ⚠ {$name}: {$error}");
        }

        return $report->skipped ? 2 : 0;
    }
}
