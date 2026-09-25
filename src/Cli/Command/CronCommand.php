<?php

declare(strict_types=1);

namespace TgJobParser\Cli\Command;

use TgJobParser\Application\ParseService;
use TgJobParser\Cli\CommandInterface;
use TgJobParser\Cli\Input;
use TgJobParser\Cli\Output;
use TgJobParser\Kernel\Config;
use TgJobParser\Repository\ParseRunRepository;
use TgJobParser\Repository\SettingsRepository;

/**
 * Для crontab: `* * * * * php bin/console cron`. Сам решает, пора ли парсить,
 * по интервалу из настроек — интервал меняется в UI без правки crontab.
 */
final class CronCommand implements CommandInterface
{
    public function __construct(
        private readonly ParseService $parser,
        private readonly ParseRunRepository $runs,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    public function name(): string
    {
        return 'cron';
    }

    public function description(): string
    {
        return 'Автопарсинг по расписанию (запускать из crontab каждую минуту)';
    }

    public function run(Input $input, Output $output): int
    {
        if (!$this->settings->get('cron.enabled', true)) {
            $output->line('Автопарсинг выключен в настройках');

            return 0;
        }
        $interval = (int) $this->settings->get('cron.interval_minutes', $this->config->get('parsing.interval_minutes', 30));
        $last = $this->runs->last();
        if ($last !== null && !$input->flag('force') && strtotime((string) $last['started_at']) > time() - $interval * 60) {
            return 0;
        }
        $output->line($this->parser->run('cron')->summary());

        return 0;
    }
}
