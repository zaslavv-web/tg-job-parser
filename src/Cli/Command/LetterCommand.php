<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;
use TgJobParser\Letter\LetterService;
use TgJobParser\Repository\PostRepository;

final class LetterCommand implements CommandInterface
{
    public function __construct(private readonly LetterService $letters, private readonly PostRepository $posts)
    {
    }

    public function name(): string
    {
        return 'letter';
    }

    public function description(): string
    {
        return 'Сгенерировать письмо: letter <post_id> [--mode=template|claude|openai] [--lang=ru|en]';
    }

    public function run(Input $input, Output $output): int
    {
        $post = $this->posts->find((int) $input->argument(0));
        if ($post === null) {
            $output->error('Пост не найден');

            return 1;
        }
        $letter = $this->letters->generateFor($post, $input->option('mode'), $input->option('lang'));
        $output->line("[{$letter->mode}, {$letter->language}]" . ($letter->note ? " ({$letter->note})" : ''));
        $output->line();
        $output->line($letter->text);

        return 0;
    }
}
