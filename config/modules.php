<?php

/**
 * Реестр модулей — «коммутационная панель» сервиса.
 *
 * Чтобы добавить возможность, не трогая ядро:
 *   - новый источник      → класс SourceDriverInterface + строка в `source_drivers`
 *   - новый шаг фильтра   → класс FilterStepInterface  + строка в `filter_steps` (+ место в pipeline.php)
 *   - новый тип правила   → класс ScoringRuleInterface + строка в `scoring_rule_types`
 *   - новый генератор     → класс LetterGeneratorInterface + строка в `letter_generators`
 *   - реакция на событие  → класс ListenerInterface + строка в `listeners`
 *   - CLI-команда         → класс CommandInterface + строка в `commands`
 * Выключить модуль = закомментировать строку. Контракты покрыты тестами (tests/).
 */

use TgJobParser\Cli\Command;
use TgJobParser\Filter\Step;
use TgJobParser\Enricher;
use TgJobParser\Letter\Generator;
use TgJobParser\Listener;
use TgJobParser\Scoring\Rule;
use TgJobParser\Source\Driver;

return [
    // Порядок важен: при автоопределении типа по вводу пользователя побеждает первый подходящий.
    'source_drivers' => [
        'telegram' => Driver\TelegramPublicDriver::class,
        'telethon' => Driver\TelethonDriver::class,
        'rss' => Driver\RssDriver::class,
        'web' => Driver\WebPageDriver::class,
    ],

    'enrichers' => [
        'language' => Enricher\LanguageEnricher::class,
        'title' => Enricher\TitleEnricher::class,
        'company' => Enricher\CompanyEnricher::class,
        'work_format' => Enricher\WorkFormatEnricher::class,
        'vacancy_link' => Enricher\VacancyLinkEnricher::class,
    ],

    'filter_steps' => [
        'vacancy_marker' => Step\VacancyMarkerStep::class,
        'target_role' => Step\TargetRoleStep::class,
        'anti_role' => Step\AntiRoleStep::class,
        'geography' => Step\GeographyStep::class,
        'excluded_domain' => Step\ExcludedDomainStep::class,
        'scoring' => Step\ScoringStep::class,
    ],

    'scoring_rule_types' => [
        'list' => Rule\ListMatchRule::class,
        'keywords' => Rule\KeywordRule::class,
        'regex' => Rule\RegexRule::class,
    ],

    // Цепочка письма: первым пробуется выбранный режим, template — всегда последний страховочный.
    'letter_generators' => [
        'template' => Generator\TemplateLetterGenerator::class,
        'claude' => Generator\ClaudeLetterGenerator::class,
        'openai' => Generator\OpenAiLetterGenerator::class,
    ],

    // Подписчики на доменные события. Ошибка подписчика логируется и не ломает парсинг.
    'listeners' => [
        'post.classified' => [
            Listener\AutoLetterListener::class,
        ],
        'parse.finished' => [
            Listener\TelegramNotifyListener::class,
        ],
        'source.failed' => [
            Listener\LogSourceFailureListener::class,
        ],
    ],

    'commands' => [
        Command\MigrateCommand::class,
        Command\ParseCommand::class,
        Command\CronCommand::class,
        Command\RescoreCommand::class,
        Command\SourcesCommand::class,
        Command\SeedCommand::class,
        Command\LetterCommand::class,
        Command\HealthCommand::class,
    ],
];
