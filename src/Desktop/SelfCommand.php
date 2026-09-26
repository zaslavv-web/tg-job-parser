<?php

declare(strict_types=1);

namespace TgJobParser\Desktop;

/** Как запустить эту же программу с аргументами — одинаково для исходников, phar и единого exe. */
final class SelfCommand
{
    /** @param list<string> $args @return list<string> */
    public static function build(array $args, string $entryScript): array
    {
        $phar = class_exists(\Phar::class) ? \Phar::running(false) : '';
        if (PHP_SAPI === 'micro') {
            // Единый исполняемый файл: PHP_BINARY — это он сам
            return array_merge([PHP_BINARY], $args);
        }

        return array_merge([PHP_BINARY, $phar !== '' ? $phar : $entryScript], $args);
    }
}
