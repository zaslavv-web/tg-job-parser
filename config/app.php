<?php

/**
 * Технические настройки. Секреты — только из окружения (.env / EnvironmentFile).
 * $env  — функция чтения переменной окружения с дефолтом (см. Kernel\Env).
 * $root — код приложения (может быть внутри исполняемого файла, только чтение).
 * $data — изменяемые данные: БД, логи, .env (в десктоп-режиме — профиль пользователя).
 */
return [
    'db' => [
        'dsn' => $env('DB_DSN') ?: 'sqlite:' . $data . '/db/database.sqlite',
        'user' => $env('DB_USER'),
        'password' => $env('DB_PASSWORD'),
    ],

    'paths' => [
        'root' => $root,
        'data' => $data,
        'templates' => $root . '/templates',
        'migrations' => $root . '/migrations',
        'log' => $data . '/log/app.log',
        'lock' => $data . '/run/parse.lock',
    ],

    'http' => [
        'timeout' => 20,
        'connect_timeout' => 8,
        'retries' => 2,
        'user_agent' => 'Mozilla/5.0 (compatible; tg-job-parser/1.0)',
    ],

    'parsing' => [
        'interval_minutes' => 30,      // автопарсинг по cron
        'max_pages_first_run' => 3,    // сколько страниц t.me/s листать при первом подключении
        'max_pages' => 5,              // потолок страниц за один проход
        'max_post_age_days' => 30,     // посты старше не сохраняем
        'source_timeout_seconds' => 90,
    ],

    'letters' => [
        'mode' => $env('LETTER_MODE') ?: 'template',   // режим по умолчанию
        'auto_generate' => true,                       // письмо для каждой подходящей вакансии при парсинге
        'auto_generate_ai' => false,                   // при парсинге AI не вызываем (деньги/лимиты) — только по кнопке
        'max_words' => ['template' => 130, 'ai' => 200],
        'forbidden_phrases' => [
            'уважаемые господа', 'я идеальный кандидат', 'идеальный кандидат', 'i am the perfect candidate',
            'perfect fit', 'идеально подхожу', 'dear sir or madam', 'to whom it may concern',
            '/\b1[89]\+?\s*(лет|years)/iu',
        ],
    ],

    'ai' => [
        'claude' => [
            'api_key' => $env('CLAUDE_API_KEY') ?: $env('ANTHROPIC_API_KEY'),
            'model' => $env('CLAUDE_MODEL') ?: 'claude-opus-5',
            'max_tokens' => 4000,
            'effort' => 'low',
        ],
        'openai' => [
            'api_key' => $env('OPENAI_API_KEY'),
            'model' => $env('OPENAI_MODEL') ?: 'gpt-4o-mini',
            'base_url' => $env('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1',
        ],
    ],

    'telethon' => [
        'python' => $env('TELETHON_PYTHON') ?: 'python3',
        'script_source' => $root . '/scripts/telethon_parser.py',   // внутри приложения
        'script' => $data . '/scripts/telethon_parser.py',          // копия, которую может запустить python
        'api_id' => $env('TG_API_ID'),
        'api_hash' => $env('TG_API_HASH'),
        'session' => $env('TG_SESSION') ?: $data . '/telethon/session',
    ],

    'notify' => [
        'telegram_bot_token' => $env('NOTIFY_TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => $env('NOTIFY_TELEGRAM_CHAT_ID'),
    ],

    // Что можно настроить из интерфейса (раздел «Подключения»); secret — не показывать значение
    'editable_env' => [
        'LETTER_MODE' => ['label' => 'Режим писем по умолчанию (template / claude / openai)', 'secret' => false],
        'CLAUDE_API_KEY' => ['label' => 'Claude API key', 'secret' => true],
        'CLAUDE_MODEL' => ['label' => 'Модель Claude', 'secret' => false],
        'OPENAI_API_KEY' => ['label' => 'OpenAI API key', 'secret' => true],
        'OPENAI_MODEL' => ['label' => 'Модель OpenAI', 'secret' => false],
        'NOTIFY_TELEGRAM_BOT_TOKEN' => ['label' => 'Токен Telegram-бота для уведомлений', 'secret' => true],
        'NOTIFY_TELEGRAM_CHAT_ID' => ['label' => 'Chat ID для уведомлений', 'secret' => false],
        'TG_API_ID' => ['label' => 'Telethon: api_id (my.telegram.org)', 'secret' => false],
        'TG_API_HASH' => ['label' => 'Telethon: api_hash', 'secret' => true],
    ],

    'web' => [
        'password' => $env('APP_PASSWORD'),
    ],

    // ТЗ, раздел 10 — каналы для первичного подключения (bin/console seed)
    'seed_sources' => [
        '@productstar', '@productmanager_jobs', '@head_of_product', '@vc_ru_work', '@habr_career',
        '@product_jobs_remote', '@no_flame_no_game', '@relocate_it', '@product_career',
    ],
];
