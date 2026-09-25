<?php

declare(strict_types=1);

namespace TgJobParser\Repository;

use TgJobParser\Database\Database;
use TgJobParser\Model\Classification;
use TgJobParser\Model\Post;
use TgJobParser\Model\RawPost;

final class PostRepository
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public function __construct(private readonly Database $db)
    {
    }

    /** Идемпотентно: повторный парсинг того же поста ничего не дублирует. Возвращает null, если пост уже был. */
    public function insertIfNew(int $sourceId, RawPost $raw): ?Post
    {
        $exists = $this->db->scalar('SELECT id FROM posts WHERE source_id = ? AND external_id = ?', [$sourceId, $raw->externalId]);
        if ($exists !== null) {
            return null;
        }
        $id = $this->db->insert('posts', [
            'source_id' => $sourceId,
            'external_id' => $raw->externalId,
            'url' => $raw->url,
            'text' => $raw->text,
            'links' => json_encode($raw->links, self::JSON),
            'buttons' => json_encode($raw->buttons, self::JSON),
            'published_at' => $raw->publishedAt?->format('Y-m-d H:i:s'),
            'status' => Post::STATUS_NEW,
            'parsed_at' => date('c'),
        ]);

        return $this->find($id);
    }

    public function exists(int $sourceId, string $externalId): bool
    {
        return $this->db->scalar('SELECT 1 FROM posts WHERE source_id = ? AND external_id = ?', [$sourceId, $externalId]) !== null;
    }

    public function find(int $id): ?Post
    {
        $row = $this->db->one(
            'SELECT p.*, s.handle AS source_handle, s.kind AS source_kind, s.title AS source_title
             FROM posts p JOIN sources s ON s.id = p.source_id WHERE p.id = ?',
            [$id],
        );

        return $row ? new Post($row) : null;
    }

    public function saveClassification(int $postId, Classification $c): void
    {
        $this->db->update('posts', [
            'is_vacancy' => $c->isVacancy() ? 1 : 0,
            'score' => $c->score,
            'status' => $c->status,
            'reject_reason' => $c->rejectReason,
            'title' => $c->attributes['title'] ?? null,
            'company' => $c->attributes['company'] ?? null,
            'work_format' => $c->attributes['work_format'] ?? null,
            'language' => $c->attributes['language'] ?? null,
            'vacancy_url' => $c->attributes['vacancy_url'] ?? null,
            'reasons' => json_encode($c->reasons, self::JSON),
            'trace' => json_encode($c->trace, self::JSON),
            'classified_at' => date('c'),
        ], ['id' => $postId]);
    }

    /** Ручное решение пользователя имеет приоритет над автоматикой и переживает пересчёт. */
    public function setManualStatus(int $postId, ?string $status): void
    {
        $this->db->update('posts', ['manual_status' => $status], ['id' => $postId]);
    }

    /**
     * @param array{statuses?: list<string>, since?: ?string, min_score?: ?int, source_id?: ?int, limit?: int} $filter
     * @return list<Post>
     */
    public function search(array $filter): array
    {
        $where = ['1 = 1'];
        $params = [];
        $statusExpr = "COALESCE(p.manual_status, p.status)";
        if (!empty($filter['statuses'])) {
            $placeholders = implode(', ', array_fill(0, count($filter['statuses']), '?'));
            $where[] = "{$statusExpr} IN ({$placeholders})";
            array_push($params, ...$filter['statuses']);
        }
        if (!empty($filter['since'])) {
            $where[] = 'COALESCE(p.published_at, p.parsed_at) >= ?';
            $params[] = $filter['since'];
        }
        if (isset($filter['min_score']) && $filter['min_score'] !== null) {
            $where[] = 'COALESCE(p.score, 0) >= ?';
            $params[] = $filter['min_score'];
        }
        if (!empty($filter['source_id'])) {
            $where[] = 'p.source_id = ?';
            $params[] = $filter['source_id'];
        }
        $limit = max(1, min(1000, (int) ($filter['limit'] ?? 200)));
        $rows = $this->db->all(
            'SELECT p.*, s.handle AS source_handle, s.kind AS source_kind, s.title AS source_title
             FROM posts p JOIN sources s ON s.id = p.source_id
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY COALESCE(p.score, -1000) DESC, COALESCE(p.published_at, p.parsed_at) DESC
             LIMIT {$limit}",
            $params,
        );

        return array_map(static fn (array $row): Post => new Post($row), $rows);
    }

    /** @return array<string, int> статус → количество */
    public function countByStatus(?string $since = null): array
    {
        $sql = 'SELECT COALESCE(manual_status, status) AS st, COUNT(*) AS n FROM posts';
        $params = [];
        if ($since !== null) {
            $sql .= ' WHERE COALESCE(published_at, parsed_at) >= ?';
            $params[] = $since;
        }
        $counts = [];
        foreach ($this->db->all($sql . ' GROUP BY st', $params) as $row) {
            $counts[(string) $row['st']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Порции постов для пересчёта после смены правил.
     *
     * @return iterable<Post>
     */
    public function iterateForRescore(?string $since = null, int $batch = 200): iterable
    {
        $lastId = 0;
        do {
            $params = [$lastId];
            $sql = 'SELECT p.*, s.handle AS source_handle, s.kind AS source_kind, s.title AS source_title
                    FROM posts p JOIN sources s ON s.id = p.source_id WHERE p.id > ?';
            if ($since !== null) {
                $sql .= ' AND COALESCE(p.published_at, p.parsed_at) >= ?';
                $params[] = $since;
            }
            $rows = $this->db->all($sql . " ORDER BY p.id LIMIT {$batch}", $params);
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                yield new Post($row);
            }
        } while (count($rows) === $batch);
    }
}
