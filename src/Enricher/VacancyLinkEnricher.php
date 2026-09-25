<?php

declare(strict_types=1);

namespace TgJobParser\Enricher;

use TgJobParser\Filter\VacancyContext;
use TgJobParser\Links\LinkExtractor;

final class VacancyLinkEnricher implements EnricherInterface
{
    public function __construct(private readonly LinkExtractor $extractor)
    {
    }

    public function enrich(VacancyContext $context): void
    {
        $link = $this->extractor->extract($context->post, $context->source);
        $context->set('vacancy_url', $link['url']);
        $context->set('vacancy_url_kind', $link['kind']);
    }
}
