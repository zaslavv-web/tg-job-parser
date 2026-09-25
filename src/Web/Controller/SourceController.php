<?php

declare(strict_types=1);

namespace TgJobParser\Web\Controller;

use TgJobParser\Application\ParseService;
use TgJobParser\Application\SourceService;
use TgJobParser\Web\Request;
use TgJobParser\Web\Response;
use TgJobParser\Web\Session;

final class SourceController extends BaseController
{
    public function __construct(Session $session, private readonly SourceService $sources, private readonly ParseService $parser)
    {
        parent::__construct($session);
    }

    public function add(Request $request): Response
    {
        $source = $this->sources->add($request->string('input'), $request->string('kind', 'auto'), [
            'assume_vacancy' => $request->input('assume_vacancy') !== null,
            'item_xpath' => $request->string('item_xpath'),
            'link_pattern' => $request->string('link_pattern'),
            'fetch_details' => $request->input('fetch_details') !== null,
        ]);

        return $this->done($request, "Источник добавлен: {$source->displayName()}", ['id' => $source->id], '/#sources');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->sources->remove((int) $id);

        return $this->done($request, 'Источник удалён', [], '/#sources');
    }

    public function toggle(Request $request, string $id): Response
    {
        $this->sources->toggle((int) $id);

        return $this->done($request, 'Статус источника изменён', [], '/#sources');
    }

    public function parse(Request $request): Response
    {
        @set_time_limit(600);
        $source = (int) $request->string('source', '0');
        $report = $this->parser->run('web', $source > 0 ? $source : null);
        $message = $report->summary();
        foreach ($report->errors as $name => $error) {
            $message .= " ⚠ {$name}: {$error}";
        }

        return $this->done($request, $message, ['report' => (array) $report], $this->back($request, '#results'));
    }
}
