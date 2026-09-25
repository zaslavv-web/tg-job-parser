<?php

declare(strict_types=1);

namespace TgJobParser\Cli;

final class Input
{
    /**
     * @param list<string> $arguments позиционные аргументы после имени команды
     * @param array<string, string|true> $options --key=value / --flag
     */
    public function __construct(public readonly array $arguments, public readonly array $options)
    {
    }

    /** @param list<string> $argv */
    public static function fromArgv(array $argv): array
    {
        $command = null;
        $arguments = [];
        $options = [];
        foreach (array_slice($argv, 1) as $token) {
            if (str_starts_with($token, '--')) {
                [$key, $value] = array_pad(explode('=', substr($token, 2), 2), 2, true);
                $options[$key] = $value;
            } elseif ($command === null) {
                $command = $token;
            } else {
                $arguments[] = $token;
            }
        }

        return [$command, new self($arguments, $options)];
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return $value === null ? $default : ($value === true ? '1' : $value);
    }

    public function flag(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function argument(int $index): ?string
    {
        return $this->arguments[$index] ?? null;
    }
}
