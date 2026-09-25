<?php

declare(strict_types=1);

namespace TgJobParser\Enricher;

use TgJobParser\Filter\VacancyContext;

/** Язык вакансии → язык письма (ТЗ 3.5): доля кириллицы среди букв. */
final class LanguageEnricher implements EnricherInterface
{
    public function enrich(VacancyContext $context): void
    {
        $context->set('language', self::detect($context->text()));
    }

    public static function detect(string $text): string
    {
        // Хэштеги и ссылки не показательны
        $text = (string) preg_replace('~(https?://\S+|#\S+|@\S+)~u', ' ', $text);
        $cyr = preg_match_all('/\p{Cyrillic}/u', $text);
        $lat = preg_match_all('/[a-zA-Z]/', $text);
        if ($cyr + $lat === 0) {
            return 'ru';
        }

        return $cyr / ($cyr + $lat) >= 0.3 ? 'ru' : 'en';
    }
}
