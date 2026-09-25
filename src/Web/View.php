<?php

declare(strict_types=1);

namespace TgJobParser\Web;

/** PHP-шаблоны из templates/: без движка, но с обязательным экранированием через $e(). */
final class View
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->directory . '/' . $template . '.html.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Шаблон не найден: {$template}");
        }
        $vars['e'] = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $vars['view'] = $this;
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
