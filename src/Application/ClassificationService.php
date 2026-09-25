<?php

declare(strict_types=1);

namespace TgJobParser\Application;

use TgJobParser\Filter\Classifier;
use TgJobParser\Kernel\EventDispatcher;
use TgJobParser\Model\Classification;
use TgJobParser\Model\Post;
use TgJobParser\Model\Source;
use TgJobParser\Repository\PostRepository;

/** Классифицирует сохранённый пост, записывает результат и публикует событие post.classified. */
final class ClassificationService
{
    public function __construct(
        private readonly Classifier $classifier,
        private readonly PostRepository $posts,
        private readonly EventDispatcher $events,
    ) {
    }

    public function classify(Post $post, Source $source, bool $isNew): Classification
    {
        $classification = $this->classifier->classify($post->toRawPost(), $source);
        $this->posts->saveClassification($post->id(), $classification);
        $this->events->dispatch('post.classified', [
            'post_id' => $post->id(),
            'status' => $classification->status,
            'score' => $classification->score,
            'is_new' => $isNew,
            'manual_status' => $post->get('manual_status'),
        ]);

        return $classification;
    }
}
