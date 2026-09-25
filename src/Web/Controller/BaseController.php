<?php

declare(strict_types=1);

namespace TgJobParser\Web\Controller;

use TgJobParser\Web\Request;
use TgJobParser\Web\Response;
use TgJobParser\Web\Session;

abstract class BaseController
{
    public function __construct(protected readonly Session $session)
    {
    }

    /** @param array<string, mixed> $json */
    protected function done(Request $request, string $message, array $json = [], string $back = '/'): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'message' => $message] + $json);
        }
        $this->session->flash('success', $message);

        return Response::redirect($back);
    }

    protected function back(Request $request, string $anchor = ''): string
    {
        $referer = $request->server['HTTP_REFERER'] ?? '/';
        $path = (string) parse_url($referer, PHP_URL_PATH);
        $query = (string) parse_url($referer, PHP_URL_QUERY);

        return ($path ?: '/') . ($query ? '?' . $query : '') . $anchor;
    }
}
