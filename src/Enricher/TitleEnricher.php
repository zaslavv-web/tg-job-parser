<?php

declare(strict_types=1);

namespace TgJobParser\Enricher;

use TgJobParser\Filter\VacancyContext;
use TgJobParser\Profile\Matcher;

/**
 * Заголовок вакансии: первая строка с упоминанием должности (из белого или анти-списка),
 * иначе первая содержательная строка. Анти-должности проверяются именно по заголовку,
 * чтобы «команда из 5 backend-разработчиков» в тексте не отклоняла вакансию продакта.
 */
final class TitleEnricher implements EnricherInterface
{
    private const ROLE_HINTS = ['manager', 'owner', 'head', 'lead', 'director', 'chief', 'vp', 'менеджер', 'руководител*', 'директор', 'аналитик', 'разработчик*', 'developer', 'engineer', 'инженер', 'тестировщик', 'qa', 'master', 'мастер', 'продакт*', 'cpo'];

    public function enrich(VacancyContext $context): void
    {
        $context->set('title', $this->extract($context));
    }

    private function extract(VacancyContext $context): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $context->text()) ?: [] as $line) {
            $clean = trim((string) preg_replace('/(#[\p{L}\p{N}_]+|https?:\/\/\S+)/u', '', $line));
            $clean = trim($clean, " \t-–—:|*•🔥📌💼✅⚡️🚀");
            if (mb_strlen($clean) >= 3) {
                $lines[] = $clean;
            }
        }
        $lines = array_slice($lines, 0, 12);
        $roleLists = [$context->profile->list('target_roles'), $context->profile->list('anti_roles')];

        foreach ($lines as $line) {
            $normalized = Matcher::normalize($line);
            if (mb_strlen($line) > 160) {
                continue;
            }
            foreach ($roleLists as $list) {
                if (Matcher::matchTerms($list, $normalized) !== []) {
                    return self::cleanup($line);
                }
            }
        }
        foreach ($lines as $line) {
            if (mb_strlen($line) <= 160 && Matcher::matchesAny(self::ROLE_HINTS, Matcher::normalize($line)) !== null) {
                return self::cleanup($line);
            }
        }

        return self::cleanup($lines[0] ?? '');
    }

    private static function cleanup(string $line): string
    {
        $line = (string) preg_replace('/^(вакансия|vacancy|позиция|position|role|роль|ищем|we are hiring|hiring)\s*[:\-–—]?\s*/iu', '', $line);

        return mb_substr(trim($line), 0, 160);
    }
}
