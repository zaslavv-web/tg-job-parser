<?php

declare(strict_types=1);

use TgJobParser\Database\Database;

/*
 * Базовая схема (ТЗ 4.3), обобщённая под «источники» (раздел 6 ТЗ):
 *   channels      → sources (kind = telegram | telethon | rss | web …)
 *   vacancy_links → posts   (+ атрибуты вакансии и объяснение решения)
 *   cover_letters, settings — как в ТЗ.
 */
return static function (Database $db): void {
    $id = $db->idColumn();

    $db->exec("CREATE TABLE sources (
        id {$id},
        kind VARCHAR(32) NOT NULL,
        handle VARCHAR(255) NOT NULL,
        url TEXT,
        title TEXT,
        options TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        last_error TEXT,
        last_post_id VARCHAR(255),
        last_parsed_at VARCHAR(32),
        created_at VARCHAR(32) NOT NULL
    )");
    $db->exec('CREATE UNIQUE INDEX sources_kind_handle ON sources (kind, handle)');

    $db->exec("CREATE TABLE posts (
        id {$id},
        source_id INTEGER NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
        external_id VARCHAR(255) NOT NULL,
        url TEXT,
        text TEXT,
        links TEXT,
        buttons TEXT,
        published_at VARCHAR(32),
        is_vacancy INTEGER NOT NULL DEFAULT 0,
        score INTEGER,
        status VARCHAR(16) NOT NULL DEFAULT 'new',
        reject_reason TEXT,
        manual_status VARCHAR(16),
        title TEXT,
        company TEXT,
        work_format TEXT,
        language VARCHAR(8),
        vacancy_url TEXT,
        reasons TEXT,
        trace TEXT,
        parsed_at VARCHAR(32) NOT NULL,
        classified_at VARCHAR(32)
    )");
    $db->exec('CREATE UNIQUE INDEX posts_source_external ON posts (source_id, external_id)');
    $db->exec('CREATE INDEX posts_status_score ON posts (status, score)');
    $db->exec('CREATE INDEX posts_published ON posts (published_at)');

    $db->exec("CREATE TABLE cover_letters (
        id {$id},
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        language VARCHAR(8) NOT NULL DEFAULT 'ru',
        text TEXT NOT NULL,
        mode VARCHAR(32) NOT NULL,
        note TEXT,
        created_at VARCHAR(32) NOT NULL
    )");
    $db->exec('CREATE INDEX cover_letters_post ON cover_letters (post_id)');

    $db->exec('CREATE TABLE settings (
        name VARCHAR(191) PRIMARY KEY,
        value TEXT
    )');

    $db->exec("CREATE TABLE parse_runs (
        id {$id},
        trigger_name VARCHAR(16) NOT NULL,
        started_at VARCHAR(32) NOT NULL,
        finished_at VARCHAR(32),
        sources_ok INTEGER NOT NULL DEFAULT 0,
        sources_failed INTEGER NOT NULL DEFAULT 0,
        posts_new INTEGER NOT NULL DEFAULT 0,
        vacancies_found INTEGER NOT NULL DEFAULT 0,
        errors TEXT
    )");
};
