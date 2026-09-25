<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Application\SourceService;
use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;
use TgJobParser\Repository\SourceRepository;

/** sources [list] | sources add <ввод> [--kind=telegram|rss|web|telethon] | sources remove <id> */
final class SourcesCommand implements CommandInterface
{
    public function __construct(private readonly SourceRepository $sources, private readonly SourceService $service)
    {
    }

    public function name(): string
    {
        return 'sources';
    }

    public function description(): string
    {
        return 'Источники: list | add <@канал|url> [--kind=…] | remove <id> | toggle <id>';
    }

    public function run(Input $input, Output $output): int
    {
        $action = $input->argument(0) ?? 'list';
        switch ($action) {
            case 'add':
                $source = $this->service->add((string) $input->argument(1), $input->option('kind'));
                $output->line("Добавлен #{$source->id}: {$source->kind} {$source->handle}");

                return 0;
            case 'remove':
                $this->service->remove((int) $input->argument(1));
                $output->line('Удалён');

                return 0;
            case 'toggle':
                $this->service->toggle((int) $input->argument(1));
                $output->line('Статус переключён');

                return 0;
            default:
                foreach ($this->sources->all() as $s) {
                    $output->line(sprintf('#%-4d %-9s %-8s %-35s %s%s', $s->id, $s->kind, $s->status, $s->handle, $s->lastParsedAt ?? '—', $s->lastError ? '  ⚠ ' . $s->lastError : ''));
                }

                return 0;
        }
    }
}
