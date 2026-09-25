<?php

declare(strict_types=1);

namespace TgJobParser\Cli;

/** CLI-команда. Регистрируется строкой в config/modules.php → commands. */
interface CommandInterface
{
    public function name(): string;

    public function description(): string;

    /** @return int код выхода */
    public function run(Input $input, Output $output): int;
}
