<?php

declare(strict_types=1);

namespace TgJobParser\Application;

use TgJobParser\Model\Post;
use TgJobParser\Repository\PostRepository;
use TgJobParser\Repository\SourceRepository;
use Throwable;

/**
 * Пересчёт уже сохранённых постов после смены правил/списков — без повторного парсинга.
 * Ручные решения пользователя (manual_status) сохраняются.
 */
final class RescoreService
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly SourceRepository $sources,
        private readonly ClassificationService $classification,
    ) {
    }

    /** @return array{processed: int, shortlisted: int, failed: int} */
    public function rescore(?string $since = null): array
    {
        $stats = ['processed' => 0, 'shortlisted' => 0, 'failed' => 0];
        $sourceCache = [];
        foreach ($this->posts->iterateForRescore($since) as $post) {
            try {
                $source = $sourceCache[$post->sourceId()] ??= $this->sources->find($post->sourceId());
                if ($source === null) {
                    continue;
                }
                $result = $this->classification->classify($post, $source, false);
                $stats['processed']++;
                if ($result->isShortlisted()) {
                    $stats['shortlisted']++;
                }
            } catch (Throwable) {
                $stats['failed']++;
            }
        }

        return $stats;
    }
}
