<?php

declare(strict_types=1);

namespace TgJobParser\Filter;

use TgJobParser\Model\Classification;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Profile\ProfileProvider;

/** Точка входа классификации: пост + источник → решение по текущему профилю. */
final class Classifier
{
    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly ProfileProvider $profiles,
    ) {
    }

    public function classify(RawPost $post, Source $source): Classification
    {
        return $this->pipeline->run(new VacancyContext($post, $source, $this->profiles->profile()));
    }
}
