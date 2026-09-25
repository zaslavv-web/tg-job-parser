<?php

declare(strict_types=1);

namespace TgJobParser\Repository;

use TgJobParser\Application\ParseReport;
use TgJobParser\Database\Database;

final class ParseRunRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function start(string $trigger): int
    {
        return $this->db->insert('parse_runs', ['trigger_name' => $trigger, 'started_at' => date('c')]);
    }

    public function finish(int $id, ParseReport $report): void
    {
        $this->db->update('parse_runs', [
            'finished_at' => date('c'),
            'sources_ok' => $report->sourcesOk,
            'sources_failed' => $report->sourcesFailed,
            'posts_new' => $report->postsNew,
            'vacancies_found' => $report->vacanciesFound,
            'errors' => $report->errors ? json_encode($report->errors, JSON_UNESCAPED_UNICODE) : null,
        ], ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        return $this->db->one('SELECT * FROM parse_runs WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1');
    }
}
