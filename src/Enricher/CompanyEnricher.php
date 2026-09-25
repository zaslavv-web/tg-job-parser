<?php

declare(strict_types=1);

namespace TgJobParser\Enricher;

use TgJobParser\Filter\VacancyContext;

/** Компания «если удалось извлечь» (ТЗ 3.6). Эвристики, от точных к общим. */
final class CompanyEnricher implements EnricherInterface
{
    private const PATTERNS = [
        '/(?:компания|company|работодатель|employer)\s*[:\-–—]\s*([^\n,.;|]{2,60})/iu',
        '/(?:^|\n)\s*🏢\s*([^\n,.;|]{2,60})/u',
        '/(?:в\s+компани[юи]|в\s+команду)\s+[«"]?([A-ZА-ЯЁ0-9][\w&\-.\s]{1,40}?)[»"]?(?=[\s,.!:;)]|$)/u',
        '/(?:^|\n)[^\n]{2,60}?[ \t]в[ \t]+([A-Z][\w&\-.]{1,30}(?:[ \t]+[A-Z][\w&\-.]{1,30}){0,2})[ \t]*(?=[,.!:;(\n]|$)/u',
        '/\b(?:at|join)[ \t]+([A-Z][\w&\-.]{1,30}(?:[ \t]+[A-Z][\w&\-.]{1,30}){0,2})/u',
        '/[«"]([A-ZА-ЯЁ][^»"\n]{1,40})[»"]/u',
    ];

    public function enrich(VacancyContext $context): void
    {
        $context->set('company', self::extract($context->text()));
    }

    public static function extract(string $text): ?string
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $name = trim($m[1], " \t*_«»\"'");
                if ($name !== '' && mb_strlen($name) <= 60) {
                    return $name;
                }
            }
        }

        return null;
    }
}
