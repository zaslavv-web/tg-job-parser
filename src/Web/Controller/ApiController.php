<?php

declare(strict_types=1);

namespace TgJobParser\Web\Controller;

use TgJobParser\Application\HealthCheck;
use TgJobParser\Application\VacancyQuery;
use TgJobParser\Web\Request;
use TgJobParser\Web\Response;
use TgJobParser\Web\Session;

/** JSON API для интеграций (бот, другой UI, Growth Peak) — поверх тех же сервисов, что и страница. */
final class ApiController extends BaseController
{
    public function __construct(Session $session, private readonly VacancyQuery $vacancies, private readonly HealthCheck $health)
    {
        parent::__construct($session);
    }

    public function vacancies(Request $request): Response
    {
        $result = $this->vacancies->shortlist(DashboardController::filters($request));
        $items = [];
        foreach ($result['items'] as ['post' => $post, 'letter' => $letter]) {
            $items[] = [
                'id' => $post->id(),
                'status' => $post->status(),
                'score' => $post->get('score') !== null ? (int) $post->get('score') : null,
                'title' => $post->get('title'),
                'company' => $post->get('company'),
                'work_format' => $post->get('work_format'),
                'vacancy_url' => $post->get('vacancy_url'),
                'post_url' => $post->get('url'),
                'published_at' => $post->get('published_at'),
                'source' => $post->get('source_handle'),
                'reasons' => $post->reasons(),
                'letter' => $letter ? ['text' => $letter['text'], 'mode' => $letter['mode'], 'language' => $letter['language']] : null,
            ];
        }

        return Response::json(['items' => $items, 'counts' => $result['counts']]);
    }

    public function health(Request $request): Response
    {
        $report = $this->health->run();

        return Response::json($report, $report['ok'] ? 200 : 503);
    }
}
