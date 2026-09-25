<?php

declare(strict_types=1);

namespace TgJobParser\Web\Controller;

use InvalidArgumentException;
use TgJobParser\Letter\LetterService;
use TgJobParser\Model\Post;
use TgJobParser\Repository\LetterRepository;
use TgJobParser\Repository\PostRepository;
use TgJobParser\Web\Request;
use TgJobParser\Web\Response;
use TgJobParser\Web\Session;

final class VacancyController extends BaseController
{
    public function __construct(
        Session $session,
        private readonly PostRepository $posts,
        private readonly LetterService $letters,
        private readonly LetterRepository $letterRepository,
    ) {
        parent::__construct($session);
    }

    /** «Отклонить вручную» */
    public function reject(Request $request, string $id): Response
    {
        $this->find($id);
        $this->posts->setManualStatus((int) $id, Post::STATUS_REJECTED);

        return $this->done($request, 'Вакансия отклонена', [], $this->back($request, '#results'));
    }

    /** «Пересмотреть» — вернуть отклонённую вакансию в выдачу, переопределив фильтр */
    public function restore(Request $request, string $id): Response
    {
        $post = $this->find($id);
        $this->posts->setManualStatus((int) $id, Post::STATUS_MAYBE);
        if (!$this->letterRepository->exists((int) $id)) {
            $this->letters->generateFor($post, 'template');
        }

        return $this->done($request, 'Вакансия возвращена в выдачу', [], $this->back($request, '#vacancy-' . (int) $id));
    }

    /** Снять ручное решение — снова решает автоматика */
    public function reset(Request $request, string $id): Response
    {
        $this->find($id);
        $this->posts->setManualStatus((int) $id, null);

        return $this->done($request, 'Ручное решение снято', [], $this->back($request, '#results'));
    }

    public function letter(Request $request, string $id): Response
    {
        $post = $this->find($id);
        $mode = $request->string('mode') ?: null;
        $lang = $request->string('lang');
        $letter = $this->letters->generateFor($post, $mode, in_array($lang, ['ru', 'en'], true) ? $lang : null);
        $message = "Письмо готово ({$letter->mode})" . ($letter->note ? ". {$letter->note}" : '');

        return $this->done($request, $message, ['letter' => (array) $letter], $this->back($request, '#vacancy-' . (int) $id));
    }

    private function find(string $id): Post
    {
        return $this->posts->find((int) $id) ?? throw new InvalidArgumentException('Вакансия не найдена');
    }
}
