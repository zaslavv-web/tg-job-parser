<?php

/**
 * Профиль кандидата (ТЗ, раздел 2) — единственный источник «что ищем».
 *
 * Каждый список — набор терминов. Термин задаётся строкой или массивом:
 *   'Head of Product'                                   — метка = шаблон
 *   ['label' => 'Head of Product', 'patterns' => [...]] — метка + синонимы
 * Шаблоны сравниваются без учёта регистра, «ё» = «е», по границам слов.
 *   'удален*'   — звёздочка = любое продолжение слова (удаленка, удаленно)
 *   '/regex/u'  — произвольное регулярное выражение
 *
 * Списки, отмеченные в `editable_lists`, пополняются из UI (раздел 5 ТЗ)
 * без правки этого файла: добавленные/удалённые элементы лежат в таблице settings.
 */
return [
    'candidate' => [
        'name' => 'Владимир Заслав',
        'phone' => '+7(926) 9882199',
        'telegram' => '@VladimirZaslav',
        'pet_project' => 'growth-peak.pro',
    ],

    'flags' => [
        // Шаг 2: отклонять пост, если должность не из белого списка
        'require_target_role' => true,
        // iGaming отклоняется, пока пользователь не разрешит (ТЗ 2.4)
        'allow_gambling' => false,
    ],

    'thresholds' => [
        'min_score' => 40,       // < min_score — отклонить
        'recommended' => 70,     // >= recommended — «рекомендуется», иначе «возможно интересно»
    ],

    'lists' => [
        // Шаг 1 — маркеры вакансии
        'vacancy_markers' => [
            'вакансия', 'вакансии', 'ищем', 'требуется', 'позиция', 'в команду',
            '#job', '#jobs', '#вакансия', '#work', '#remote', '#продукт', '#product', '#hiring',
            'hiring', "we're hiring", 'we are hiring', 'open position', 'job opening', 'looking for',
        ],

        // 2.1 — целевые должности
        'target_roles' => [
            ['label' => 'Product Manager', 'patterns' => ['product manager', 'продакт-менеджер', 'продакт менеджер', 'продакт', 'менеджер продукта', 'менеджер по продукту', 'продуктовый менеджер', 'pm']],
            ['label' => 'Product Owner', 'patterns' => ['product owner', 'владелец продукта']],
            ['label' => 'Senior Product Manager', 'patterns' => ['senior product manager', 'senior pm', 'старший продакт*']],
            ['label' => 'Lead Product Manager', 'patterns' => ['product lead', 'lead product manager', 'lead pm', 'лид продукта', 'руководитель продукта']],
            ['label' => 'Group Product Manager', 'patterns' => ['group product manager', 'gpm']],
            ['label' => 'Head of Product', 'patterns' => ['head of product', 'хед продукта', 'руководитель продуктового направления', 'руководитель направления продукт*']],
            ['label' => 'CPO', 'patterns' => ['chief product officer', 'cpo']],
            ['label' => 'Director of Product', 'patterns' => ['director of product', 'product director', 'директор по продукту', 'продуктовый директор']],
            ['label' => 'VP of Product', 'patterns' => ['vp of product', 'vp product', 'vice president of product']],
        ],

        // Подмножество для бонуса +10 в скоринге
        'senior_roles' => [
            ['label' => 'Head of Product', 'patterns' => ['head of product', 'хед продукта', 'руководитель продуктового направления']],
            ['label' => 'CPO', 'patterns' => ['chief product officer', 'cpo']],
            ['label' => 'Director', 'patterns' => ['director of product', 'product director', 'директор по продукту', 'продуктовый директор', 'vp of product', 'vp product']],
        ],

        // 2.2 — анти-должности (проверяются по заголовку вакансии).
        // `unless` — если в заголовке есть продуктовая составляющая, анти-правило не срабатывает.
        'anti_roles' => [
            ['label' => 'Junior / Middle', 'patterns' => ['junior', 'middle', 'джун*', 'мидл*', 'младший', 'стажер*', 'intern*']],
            ['label' => 'Project Manager', 'patterns' => ['project manager', 'менеджер проект*', 'руководитель проект*', 'проджект*'], 'unless' => ['product', 'продукт*', 'продакт*']],
            ['label' => 'System / Business Analyst', 'patterns' => ['system analyst', 'business analyst', 'системный аналитик', 'бизнес-аналитик', 'бизнес аналитик']],
            ['label' => 'QA / Тестировщик', 'patterns' => ['qa', 'тестировщик*', 'qa engineer', 'тестирован*'], 'unless' => ['product manager', 'product owner', 'продакт*', 'менеджер продукт*']],
            ['label' => 'Разработчик', 'patterns' => ['frontend', 'backend', 'fullstack', 'full-stack', 'devops', 'developer', 'разработчик*', 'программист*'], 'unless' => ['product manager', 'product owner', 'продакт*', 'менеджер продукт*', 'head of product']],
            ['label' => 'Data Analyst / Engineer', 'patterns' => ['data analyst', 'data engineer', 'аналитик данных', 'дата-аналитик*']],
            ['label' => 'Scrum Master', 'patterns' => ['scrum master', 'скрам-мастер', 'agile coach'], 'unless' => ['product owner', 'product manager']],
        ],

        // 2.3 — домены-приоритеты
        'priority_domains' => [
            ['label' => 'B2B SaaS', 'patterns' => ['b2b saas', 'saas', 'b2b'], 'generic' => true],
            ['label' => 'Fintech', 'patterns' => ['fintech', 'финтех', 'банк', 'банков*', 'платеж*', 'payments', 'payment', 'эквайринг*', 'bank*']],
            ['label' => 'HR-tech', 'patterns' => ['hr-tech', 'hrtech', 'hr tech', 'lms', 'ats', 'wfm', 'рекрутинг*', 'hr-платформ*']],
            ['label' => 'Enterprise', 'patterns' => ['enterprise', 'энтерпрайз', 'корпоративн* платформ*'], 'generic' => true],
            ['label' => 'AI / LLM', 'patterns' => ['ai', 'llm', 'ии', 'ai-агент*', 'ai агент*', 'machine learning', 'ml', 'искусственн* интеллект*', 'genai', 'gen ai', 'нейросет*']],
            ['label' => 'E-com / маркетплейсы', 'patterns' => ['e-com', 'ecom', 'e-commerce', 'ecommerce', 'маркетплейс*', 'marketplace*', 'wildberries', 'ozon', 'ритейл*']],
            ['label' => 'EdTech', 'patterns' => ['edtech', 'эдтех', 'онлайн-образован*', 'образовательн* платформ*']],
        ],

        // 2.4 — домены-исключения. action: reject | penalty (штраф в скоринге)
        'excluded_domains' => [
            ['label' => 'iGaming', 'patterns' => ['igaming', 'gambling', 'гемблинг*', 'казино', 'casino', 'букмекер*', 'betting', 'ставки на спорт', 'беттинг*'], 'action' => 'reject', 'flag' => 'allow_gambling'],
            ['label' => 'AdTech', 'patterns' => ['adtech', 'programmatic', 'ssp', 'dsp', 'adexchange', 'ad exchange', 'рекламн* сет*'], 'action' => 'reject'],
            ['label' => 'Геймдев', 'patterns' => ['gamedev', 'геймдев*', 'game studio', 'игров* студи*', 'разработк* игр', 'mobile games'], 'action' => 'reject'],
            ['label' => 'Консалтинг без продукта', 'patterns' => ['консалтинг*', 'consulting', 'аутстаф*', 'outstaff*', 'аутсорс*', 'outsourc*'], 'action' => 'penalty'],
        ],

        // 2.5 — география
        'remote_markers' => ['удален*', 'удалённ*', 'remote', 'дистанцион*', 'из любой точки', 'work from anywhere', 'anywhere', 'fully remote', 'full remote', '#remote', '#удаленка'],
        'hybrid_markers' => ['гибрид*', 'hybrid'],
        'office_markers' => ['офис*', 'office', 'on-site', 'onsite', 'on site'],
        'relocation_markers' => ['релокац*', 'relocation', 'relocate', 'переезд*'],

        'allowed_hybrid_cities' => [
            ['label' => 'Алматы', 'patterns' => ['алматы', 'almaty']],
            ['label' => 'Астана', 'patterns' => ['астана', 'astana']],
            ['label' => 'Тбилиси', 'patterns' => ['тбилиси', 'tbilisi']],
            ['label' => 'Лиссабон', 'patterns' => ['лиссабон*', 'lisbon']],
            ['label' => 'Лимассол', 'patterns' => ['лимассол*', 'limassol']],
            ['label' => 'Дубай', 'patterns' => ['дубай', 'dubai']],
        ],

        'allowed_relocation' => [
            ['label' => 'Казахстан', 'patterns' => ['казахстан*', 'kazakhstan']],
            ['label' => 'Грузия', 'patterns' => ['грузия', 'грузии', 'грузию', 'тбилиси', 'georgia']],
            ['label' => 'Кипр', 'patterns' => ['кипр*', 'cyprus']],
            ['label' => 'ОАЭ', 'patterns' => ['оаэ', 'uae', 'эмират*', 'emirates', 'дубай', 'dubai']],
            ['label' => 'Узбекистан', 'patterns' => ['узбекистан*', 'uzbekistan', 'ташкент*', 'tashkent']],
            ['label' => 'Испания', 'patterns' => ['испани*', 'spain', 'мадрид*', 'барселон*']],
            ['label' => 'Чехия', 'patterns' => ['чехи*', 'czech*', 'прага', 'prague']],
            ['label' => 'Азия', 'patterns' => ['азия', 'asia', 'сингапур*', 'singapore', 'таиланд*', 'thailand', 'вьетнам*', 'vietnam', 'малайзи*', 'malaysia', 'индонези*', 'indonesia', 'бали', 'bali', 'япони*', 'japan', 'гонконг*', 'hong kong', 'южн* коре*', 'south korea', 'армени*', 'armenia', 'ереван*']],
            ['label' => 'США (удалённо)', 'patterns' => ['сша', 'usa', 'united states'], 'requires_remote' => true],
        ],

        // Неприемлемо. `allow_if_remote` — не отклонять, если есть полная удалёнка.
        'blocked_locations' => [
            ['label' => 'Великобритания', 'patterns' => ['великобритани*', 'united kingdom', 'uk', 'лондон*', 'london', 'англия', 'англии', 'england']],
            ['label' => 'Канада', 'patterns' => ['канад*', 'canada', 'торонто', 'toronto', 'ванкувер*', 'vancouver']],
            ['label' => 'Индия', 'patterns' => ['индия', 'индии', 'индию', 'india', 'бангалор*', 'bangalore', 'bengaluru']],
            ['label' => 'Северная Корея', 'patterns' => ['северн* коре*', 'кндр', 'north korea']],
            ['label' => 'Офис Санкт-Петербург', 'patterns' => ['санкт-петербург*', 'спб', 'питер*', 'saint petersburg', 'st. petersburg'], 'allow_if_remote' => true],
        ],

        // 2.6 — технические требования без продуктового контекста (штраф)
        'hard_tech_requirements' => [
            '/опыт\w*\s+(работы\s+)?с\s+[a-z][\w\-]+\s+от\s+\d+\s+(лет|года)/u',
            'senior engineer', 'пишет production-код', 'production code',
        ],

        // Требования к сопроводительному письму, добавленные пользователем (раздел 5)
        'letter_requirements' => [],
    ],

    // Какие списки можно пополнять из UI — и как они там называются
    'editable_lists' => [
        'vacancy_markers' => 'Ключевые слова — маркеры вакансии',
        'target_roles' => 'Целевые должности (белый список)',
        'anti_roles' => 'Анти-должности (чёрный список)',
        'priority_domains' => 'Домены-приоритеты',
        'excluded_domains' => 'Домены-исключения',
        'allowed_hybrid_cities' => 'Локации: города для гибрида (белый список)',
        'allowed_relocation' => 'Локации: страны релокации (белый список)',
        'blocked_locations' => 'Локации: чёрный список',
        'letter_requirements' => 'Требования к сопроводительному письму',
    ],

    /**
     * 2.7 — опыт кандидата. ТОЛЬКО эти факты попадают в письма.
     * tags — ключевые слова вакансии, по которым кейс считается релевантным.
     */
    'experience' => [
        [
            'id' => 'antiplagiat',
            'project' => 'Антиплагиат',
            'result_ru' => 'запустил MVP AI-детекции, прогноз ×2 cashflow',
            'result_en' => 'launched an AI-detection MVP with a forecast of 2x cashflow',
            'tags' => ['ai', 'llm', 'ии', 'edtech', 'b2b', 'saas', 'mvp', 'zero-to-one', 'с нуля', 'монетизац*', 'нейросет*', 'machine learning', 'ml'],
        ],
        [
            'id' => 'ais',
            'project' => 'АИС',
            'result_ru' => 'перевёл продукт на рыночные решения, экономия 15 млн ₽',
            'result_en' => 'moved the product to market solutions, saving 15M RUB',
            'tags' => ['enterprise', 'b2b', 'экономи*', 'cost', 'миграц*', 'platform', 'платформ*', 'p&l', 'unit-эконом*'],
        ],
        [
            'id' => 'rzd',
            'project' => 'РЖД',
            'result_ru' => '4 проекта на 230 млн ₽, HR-tech с VR за 3 месяца, 30 000 MAU',
            'result_en' => '4 projects worth 230M RUB, an HR-tech VR product in 3 months, 30,000 MAU',
            'tags' => ['hr-tech', 'hrtech', 'hr', 'lms', 'ats', 'wfm', 'enterprise', 'корпорат*', 'mau', 'vr', 'edtech', 'обучени*'],
        ],
        [
            'id' => 'pooling',
            'project' => 'Pooling',
            'result_ru' => '+10% к обороту за 1 месяц разработки',
            'result_en' => '+10% turnover after one month of development',
            'tags' => ['e-com', 'ecom', 'e-commerce', 'маркетплейс*', 'marketplace*', 'gmv', 'рост*', 'growth', 'оборот*', 'конверси*'],
        ],
        [
            'id' => 'neurocity',
            'project' => 'NeuroCity',
            'result_ru' => 'пилот в фудмолле «Депо», 20% заказов точки',
            'result_en' => 'a pilot in the "Depo" food mall with 20% of the venue\'s orders',
            'tags' => ['ai', 'ии', 'нейросет*', 'food', 'ритейл*', 'retail', 'offline', 'пилот*', 'pilot', 'zero-to-one', 'с нуля'],
        ],
        [
            'id' => 'sep',
            'project' => 'СЭП',
            'result_ru' => 'вывел 250+ клиентов из долгов, 90% кредитных организаций на платформе, работа с Минфином и ЦБ, продукт стал основой для закона',
            'result_en' => 'helped 250+ clients out of debt, onboarded 90% of credit institutions, worked with the Ministry of Finance and the Central Bank; the product became the basis for a law',
            'tags' => ['fintech', 'финтех', 'банк*', 'bank*', 'кредит*', 'credit', 'платеж*', 'payment*', 'b2g', 'регулятор*', 'compliance', 'enterprise'],
        ],
        [
            'id' => 'saas_marketplaces',
            'project' => 'SaaS для маркетплейсов',
            'result_ru' => '×2 LTV, рост Retention и Conversion',
            'result_en' => '2x LTV, improved retention and conversion',
            'tags' => ['saas', 'b2b', 'маркетплейс*', 'marketplace*', 'e-com', 'ecom', 'ltv', 'retention', 'ретеншн', 'конверси*', 'conversion', 'монетизац*', 'unit-эконом*', 'unit economics', 'подписк*', 'subscription'],
        ],
        [
            'id' => 'career_track',
            'project' => 'Career Track OS',
            'result_ru' => 'HR-платформа из 16 модулей, построенная с AI-разработкой',
            'result_en' => 'an HR platform of 16 modules built with AI-assisted development',
            'tags' => ['hr-tech', 'hrtech', 'hr', 'lms', 'ats', 'wfm', 'saas', 'b2b', 'platform', 'платформ*', 'ai'],
        ],
    ],

    // Пет-проект упоминается, если вакансия про AI или product-building
    'pet_project' => [
        'text_ru' => 'Параллельно сделал пет-проект growth-peak.pro — написал его через AI (Claude) от архитектуры до деплоя.',
        'text_en' => 'I also built a pet project, growth-peak.pro, end to end with AI (Claude) — from architecture to deployment.',
        'triggers' => ['ai', 'ии', 'llm', 'ai-агент*', 'нейросет*', 'genai', 'product-building', 'zero-to-one', 'с нуля', 'mvp', 'прототип*', 'prototype*', 'cursor', 'claude code', 'vibe coding', 'вайбкодинг*'],
    ],
];
