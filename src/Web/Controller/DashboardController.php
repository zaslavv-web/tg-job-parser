<?php

declare(strict_types=1);

namespace TgJobParser\Web\Controller;

use TgJobParser\Application\VacancyQuery;
use TgJobParser\Kernel\Config;
use TgJobParser\Letter\LetterService;
use TgJobParser\Model\Post;
use TgJobParser\Profile\ProfileProvider;
use TgJobParser\Repository\ParseRunRepository;
use TgJobParser\Repository\SettingsRepository;
use TgJobParser\Repository\SourceRepository;
use TgJobParser\Source\SourceRegistry;
use TgJobParser\Web\Request;
use TgJobParser\Web\Response;
use TgJobParser\Web\Session;
use TgJobParser\Web\View;

final class DashboardController extends BaseController
{
    public function __construct(
        Session $session,
        private readonly View $view,
        private readonly VacancyQuery $vacancies,
        private readonly SourceRepository $sources,
        private readonly SourceRegistry $registry,
        private readonly ProfileProvider $profiles,
        private readonly LetterService $letters,
        private readonly ParseRunRepository $runs,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
        parent::__construct($session);
    }

    public function index(Request $request): Response
    {
        $filters = self::filters($request);
        $shortlist = $this->vacancies->shortlist($filters);
        $counts = $shortlist['counts'];
        $lists = [];
        foreach ($this->profiles->editableLists() as $name => $label) {
            $lists[$name] = ['label' => $label, 'items' => $this->profiles->describeList($name)];
        }

        return new Response($this->view->render('index', [
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'filters' => $filters,
            'items' => $shortlist['items'],
            'found' => ($counts[Post::STATUS_RECOMMENDED] ?? 0) + ($counts[Post::STATUS_MAYBE] ?? 0),
            'rejectedCount' => $counts[Post::STATUS_REJECTED] ?? 0,
            'notVacancyCount' => $counts[Post::STATUS_NOT_VACANCY] ?? 0,
            'rejected' => $this->vacancies->rejected($filters['period'], 100),
            'sources' => $this->sources->all(),
            'drivers' => $this->registry->all(),
            'lists' => $lists,
            'profile' => $this->profiles->profile(),
            'flagsConfig' => array_keys((array) $this->config->get('profile.flags', [])),
            'letterModes' => $this->letters->availableModes(),
            'defaultMode' => (string) $this->config->get('letters.mode', 'template'),
            'lastRun' => $this->runs->last(),
            'cron' => [
                'enabled' => (bool) $this->settings->get('cron.enabled', true),
                'interval' => (int) $this->settings->get('cron.interval_minutes', $this->config->get('parsing.interval_minutes', 30)),
            ],
        ]));
    }

    /** @return array{show: list<string>, period: string, min_score: int, source_id: ?int} */
    public static function filters(Request $request): array
    {
        $show = $request->query['show'] ?? [Post::STATUS_RECOMMENDED, Post::STATUS_MAYBE];
        $period = $request->string('period', 'all');

        return [
            'show' => array_values(array_filter((array) $show, 'is_string')),
            'period' => in_array($period, ['today', 'week', 'all'], true) ? $period : 'all',
            'min_score' => max(0, min(100, (int) $request->string('min_score', '0'))),
            'source_id' => ($id = (int) $request->string('source', '0')) > 0 ? $id : null,
        ];
    }
}
