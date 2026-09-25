<?php

declare(strict_types=1);

namespace TgJobParser\Listener;

use TgJobParser\Kernel\Config;
use TgJobParser\Kernel\ListenerInterface;
use TgJobParser\Letter\LetterService;
use TgJobParser\Repository\LetterRepository;
use TgJobParser\Repository\PostRepository;

/** Для каждой новой подходящей вакансии сразу готовит письмо (шаблонное; AI — по настройке). */
final class AutoLetterListener implements ListenerInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly LetterService $letters,
        private readonly LetterRepository $letterRepository,
        private readonly PostRepository $posts,
    ) {
    }

    public function handle(array $payload): void
    {
        if (!$this->config->get('letters.auto_generate', true)) {
            return;
        }
        if (!in_array($payload['status'] ?? null, ['recommended', 'maybe'], true)) {
            return;
        }
        $postId = (int) $payload['post_id'];
        if ($this->letterRepository->exists($postId)) {
            return;
        }
        $post = $this->posts->find($postId);
        if ($post === null) {
            return;
        }
        $mode = $this->config->get('letters.auto_generate_ai', false) ? null : 'template';
        $this->letters->generateFor($post, $mode);
    }
}
