<?php

declare(strict_types=1);

namespace TgJobParser\Desktop;

final class Browser
{
    public static function open(string $url): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Windows' => ['rundll32', 'url.dll,FileProtocolHandler', $url],
            'Darwin' => ['open', $url],
            default => ['xdg-open', $url],
        };
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        proc_close($process);

        return true;
    }
}
