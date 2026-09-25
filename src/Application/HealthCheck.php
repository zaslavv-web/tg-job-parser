<?php

declare(strict_types=1);

namespace TgJobParser\Application;

use TgJobParser\Database\Database;
use TgJobParser\Database\Migrator;
use TgJobParser\Filter\Pipeline;
use TgJobParser\Letter\LetterService;
use TgJobParser\Repository\ParseRunRepository;
use TgJobParser\Source\SourceRegistry;
use Throwable;

/**
 * Самодиагностика для /api/health и `bin/console health`.
 * critical — без этого сервис не работает; остальное — опциональные модули.
 */
final class HealthCheck
{
    public function __construct(
        private readonly Database $db,
        private readonly Migrator $migrator,
        private readonly SourceRegistry $sources,
        private readonly LetterService $letters,
        private readonly ParseRunRepository $runs,
        private readonly \TgJobParser\Kernel\Container $container,
    ) {
    }

    /** @return array{ok: bool, checks: list<array{name: string, ok: bool, critical: bool, detail: string}>} */
    public function run(): array
    {
        $checks = [];
        $checks[] = $this->check('База данных', true, fn (): string => $this->db->driver() . ' ' . ($this->db->scalar('SELECT 1') == 1 ? 'ok' : 'нет ответа'));
        $checks[] = $this->check('Миграции', true, function (): string {
            $pending = $this->migrator->status();
            if ($pending !== []) {
                throw new \RuntimeException('не применены: ' . implode(', ', $pending) . ' → php bin/console migrate');
            }

            return 'актуальны';
        });
        $checks[] = $this->check('Конвейер фильтров', true, function (): string {
            $this->container->get(Pipeline::class);

            return 'собран';
        });
        foreach ($this->sources->all() as $kind => $driver) {
            $reason = $driver->unavailableReason();
            $checks[] = ['name' => "Источник: {$kind}", 'ok' => $reason === null, 'critical' => false, 'detail' => $reason ?? 'готов'];
        }
        foreach ($this->letters->availableModes() as $mode => $reason) {
            $checks[] = ['name' => "Письма: {$mode}", 'ok' => $reason === null, 'critical' => $mode === 'template', 'detail' => $reason ?? 'готов'];
        }
        $last = $this->runs->last();
        $checks[] = ['name' => 'Последний парсинг', 'ok' => true, 'critical' => false, 'detail' => $last ? (string) $last['finished_at'] : 'ещё не было'];

        $ok = true;
        foreach ($checks as $check) {
            if ($check['critical'] && !$check['ok']) {
                $ok = false;
            }
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    /** @param callable(): string $probe */
    private function check(string $name, bool $critical, callable $probe): array
    {
        try {
            return ['name' => $name, 'ok' => true, 'critical' => $critical, 'detail' => $probe()];
        } catch (Throwable $e) {
            return ['name' => $name, 'ok' => false, 'critical' => $critical, 'detail' => $e->getMessage()];
        }
    }
}
