<?php

declare(strict_types=1);

namespace TgJobParser\Source\Driver;

use RuntimeException;

/** Запуск внешнего процесса без shell-интерполяции, с таймаутом (для Telethon-скрипта). */
class ProcessRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function run(array $command, int $timeoutSeconds, array $env = []): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить процесс: ' . $command[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $exit = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException("Процесс превысил таймаут {$timeoutSeconds} с");
            }
            usleep(50_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
