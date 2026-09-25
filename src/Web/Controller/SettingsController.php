<?php

declare(strict_types=1);

namespace TgJobParser\Web\Controller;

use TgJobParser\Application\RescoreService;
use TgJobParser\Profile\ProfileProvider;
use TgJobParser\Repository\SettingsRepository;
use TgJobParser\Web\Request;
use TgJobParser\Web\Response;
use TgJobParser\Web\Session;

/**
 * Раздел 5 ТЗ: новые локации, ключевые слова, требования к письму — без правки кода.
 * После изменения правил сохранённые посты пересчитываются, чтобы выдача сразу отражала правку.
 */
final class SettingsController extends BaseController
{
    public function __construct(
        Session $session,
        private readonly ProfileProvider $profiles,
        private readonly SettingsRepository $settings,
        private readonly RescoreService $rescore,
    ) {
        parent::__construct($session);
    }

    public function addItem(Request $request): Response
    {
        $list = $request->string('list');
        $value = $request->string('value');
        $this->profiles->addToList($list, $value);

        return $this->afterRulesChange($request, "Добавлено: {$value}");
    }

    public function removeItem(Request $request): Response
    {
        $this->profiles->removeFromList($request->string('list'), $request->string('value'));

        return $this->afterRulesChange($request, 'Удалено: ' . $request->string('value'));
    }

    public function restoreItem(Request $request): Response
    {
        $this->profiles->restoreInList($request->string('list'), $request->string('value'));

        return $this->afterRulesChange($request, 'Возвращено: ' . $request->string('value'));
    }

    public function general(Request $request): Response
    {
        $flags = [];
        foreach ((array) ($request->post['flags_present'] ?? []) as $flag) {
            $flags[(string) $flag] = isset($request->post['flags'][$flag]);
        }
        $this->profiles->setFlags($flags);
        $this->profiles->setThresholds([
            'min_score' => (int) $request->string('min_score', '40'),
            'recommended' => (int) $request->string('recommended', '70'),
        ]);
        $this->settings->set('cron.enabled', $request->input('cron_enabled') !== null);
        $this->settings->set('cron.interval_minutes', max(5, min(1440, (int) $request->string('cron_interval', '30'))));

        return $this->afterRulesChange($request, 'Настройки сохранены');
    }

    public function rescore(Request $request): Response
    {
        $stats = $this->rescore->rescore();

        return $this->done($request, "Пересчитано постов: {$stats['processed']}, подходящих: {$stats['shortlisted']}", $stats, '/#results');
    }

    private function afterRulesChange(Request $request, string $message): Response
    {
        @set_time_limit(300);
        $stats = $this->rescore->rescore();

        return $this->done($request, "{$message}. Пересчитано постов: {$stats['processed']}", $stats, '/#settings');
    }
}
