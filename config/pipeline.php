<?php

/**
 * Как пост превращается в оценённую вакансию (ТЗ 3.3, 3.4, 6).
 *
 * Всё здесь — данные: порядок шагов, баллы, стратегии ссылок.
 * Новый шаг/правило/тип правила = класс + строка в этом файле; ядро не трогаем.
 */
return [
    // Обогатители: вытаскивают атрибуты поста до фильтров (заголовок, язык, компания…)
    'enrichers' => [
        'language',
        'title',
        'company',
        'work_format',
        'vacancy_link',
    ],

    // Цепочка фильтров (3.3). Порядок = порядок выполнения.
    // on_error: continue — упавший шаг пропускается (сервис не падает), reject — пост отклоняется.
    'steps' => [
        ['id' => 'vacancy_marker', 'on_error' => 'continue'],
        ['id' => 'target_role', 'on_error' => 'continue'],
        ['id' => 'anti_role', 'on_error' => 'continue'],
        ['id' => 'geography', 'on_error' => 'continue'],
        ['id' => 'excluded_domain', 'on_error' => 'continue'],
        ['id' => 'scoring', 'on_error' => 'reject'],
    ],

    // Скоринг (3.4). type → класс правила из modules.php (scoring_rule_types).
    // scope: title | text. unless: список или шаблоны, при совпадении правило не срабатывает.
    'scoring_rules' => [
        ['id' => 'target_role', 'type' => 'list', 'list' => 'target_roles', 'scope' => 'any', 'max_labels' => 1, 'points' => 20, 'label' => 'Должность: {match} (целевая)'],
        ['id' => 'senior_role', 'type' => 'list', 'list' => 'senior_roles', 'scope' => 'any', 'max_labels' => 1, 'points' => 10, 'label' => 'Уровень: {match}'],
        ['id' => 'priority_domain', 'type' => 'list', 'list' => 'priority_domains', 'points' => 15, 'label' => 'Домен: {match}'],
        ['id' => 'ai', 'type' => 'keywords', 'points' => 10, 'label' => 'Упоминание AI-продуктов',
            'patterns' => ['ai', 'ии', 'llm', 'ai-агент*', 'ai агент*', 'ai-продукт*', 'genai', 'нейросет*', 'искусственн* интеллект*', 'machine learning', 'ml']],
        ['id' => 'remote', 'type' => 'list', 'list' => 'remote_markers', 'points' => 10, 'label' => 'Удалёнка'],
        ['id' => 'monetization', 'type' => 'keywords', 'points' => 10, 'label' => 'Монетизация / P&L / unit-экономика',
            'patterns' => ['монетизац*', 'monetization', 'p&l', 'pnl', 'unit-эконом*', 'юнит-эконом*', 'unit economics', 'выручк*', 'revenue']],
        ['id' => 'b2b', 'type' => 'keywords', 'points' => 8, 'label' => 'B2B / Enterprise',
            'patterns' => ['b2b', 'enterprise', 'энтерпрайз', 'корпоративн* клиент*']],
        ['id' => 'zero_to_one', 'type' => 'keywords', 'points' => 7, 'label' => 'Запуск с нуля',
            'patterns' => ['zero-to-one', 'zero to one', '0 to 1', '0→1', '0-1', 'с нуля', 'запуск* нов* продукт*', 'from scratch', 'greenfield']],
        ['id' => 'salary', 'type' => 'regex', 'points' => 5, 'label' => 'Указана зарплата / вилка',
            'patterns' => ['/[$€]\s?\d/u', '/\d[\d\s.,]*\s?(k\s?)?(\$|€|usd|eur)/iu', '/\d[\d\s]*\s?[-–—]\s?\d[\d\s]*\s?(тыс|k|к|000|₽|руб)/iu', '/(зарплат\w*|з\/п|зп|вилк\w*|salary|compensation)\s*[:\-–—]?\s*(от|до|from|up to)?\s*\d/iu']],
        ['id' => 'accredited', 'type' => 'keywords', 'points' => 3, 'label' => 'Аккредитованная IT-компания',
            'patterns' => ['аккредитованн* it', 'аккредитованн* ит', 'it-аккредитац*', 'аккредитаци* минцифры']],
        ['id' => 'excluded_domain', 'type' => 'list', 'list' => 'excluded_domains', 'points' => -30, 'label' => 'Домен из исключений: {match}'],
        ['id' => 'moscow_office', 'type' => 'keywords', 'points' => -25, 'label' => 'Офис в Москве без удалёнки',
            'patterns' => ['москв*', 'moscow', 'мск'], 'unless_list' => 'remote_markers'],
        ['id' => 'junior_experience', 'type' => 'regex', 'points' => -20, 'label' => 'Требуемый опыт < 2 лет',
            'patterns' => ['/опыт\w*[^.\n]{0,30}?(от|не менее)\s*(0|1|одного|года|полугода)\b(?!\s*[-–—]?\s*\d)/iu', '/\b[01]\+?\s*(year|years|год|года)\b/iu', '/без опыта/iu', '/no experience required/iu']],
        ['id' => 'hard_tech', 'type' => 'list', 'list' => 'hard_tech_requirements', 'points' => -15, 'label' => 'Требуется глубокая техническая экспертиза'],
    ],

    // Приоритет извлечения ссылки на вакансию (ТЗ 6). Больше priority — важнее.
    'link_strategies' => [
        ['label' => 'job_board', 'priority' => 100, 'hosts' => ['/(^|\.)hh\.(ru|kz)$/', '/(^|\.)headhunter\.\w+$/', '/(^|\.)career\.habr\.com$/', '/(^|\.)habr\.career$/', '/(^|\.)linkedin\.com$/', '/^careers\./', '/(^|\.)getmatch\.ru$/', '/(^|\.)geekjob\.ru$/', '/(^|\.)superjob\.ru$/', '/(^|\.)lever\.co$/', '/(^|\.)greenhouse\.io$/', '/(^|\.)workable\.com$/', '/(^|\.)ashbyhq\.com$/', '/(^|\.)huntflow\.(ru|io)$/'],
            'paths' => []],
        ['label' => 'application_form', 'priority' => 80, 'hosts' => ['/(^|\.)typeform\.com$/', '/^forms\.gle$/', '/^docs\.google\.com$/', '/(^|\.)notion\.(so|site)$/', '/(^|\.)forms\.yandex\.ru$/', '/(^|\.)tally\.so$/'],
            'paths' => []],
        ['label' => 'company_careers', 'priority' => 60, 'hosts' => [],
            'paths' => ['/\/(careers?|jobs?|vacanc(y|ies)|вакансии)(\/|$|\?)/iu']],
        ['label' => 'telegram_contact', 'priority' => 20, 'hosts' => ['/^t\.me$/', '/^telegram\.me$/'], 'paths' => []],
    ],
];
