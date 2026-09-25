<?php

declare(strict_types=1);

namespace TgJobParser\Cli;

final class Output
{
    /** @var resource */
    private $stream;

    /** @param resource|null $stream */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDOUT;
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    /** Дополнение пробелами с учётом UTF-8 (mb_str_pad есть только с PHP 8.3). */
    public static function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
    }

    public function error(string $text): void
    {
        fwrite(STDERR, $text . PHP_EOL);
    }
}
