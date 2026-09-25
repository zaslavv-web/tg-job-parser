<?php

declare(strict_types=1);

namespace TgJobParser\Repository;

use TgJobParser\Database\Database;
use TgJobParser\Model\Source;

final class SourceRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<Source> */
    public function all(): array
    {
        return array_map([Source::class, 'fromRow'], $this->db->all('SELECT * FROM sources ORDER BY kind, handle'));
    }

    /** @return list<Source> */
    public function active(): array
    {
        return array_map([Source::class, 'fromRow'], $this->db->all('SELECT * FROM sources WHERE is_active = 1 ORDER BY id'));
    }

    public function find(int $id): ?Source
    {
        $row = $this->db->one('SELECT * FROM sources WHERE id = ?', [$id]);

        return $row ? Source::fromRow($row) : null;
    }

    public function findByHandle(string $kind, string $handle): ?Source
    {
        $row = $this->db->one('SELECT * FROM sources WHERE kind = ? AND handle = ?', [$kind, $handle]);

        return $row ? Source::fromRow($row) : null;
    }

    public function create(Source $source): Source
    {
        $id = $this->db->insert('sources', [
            'kind' => $source->kind,
            'handle' => $source->handle,
            'url' => $source->url,
            'title' => $source->title,
            'options' => $source->options ? json_encode($source->options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'is_active' => $source->isActive ? 1 : 0,
            'status' => $source->isActive ? Source::STATUS_ACTIVE : Source::STATUS_DISABLED,
            'created_at' => date('c'),
        ]);

        return $this->find($id) ?? throw new \RuntimeException('Источник не сохранился');
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM sources WHERE id = ?', [$id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update('sources', [
            'is_active' => $active ? 1 : 0,
            'status' => $active ? Source::STATUS_ACTIVE : Source::STATUS_DISABLED,
        ], ['id' => $id]);
    }

    public function markParsed(int $id, ?string $cursor, ?string $title = null): void
    {
        $row = ['status' => Source::STATUS_ACTIVE, 'last_error' => null, 'last_parsed_at' => date('c')];
        if ($cursor !== null) {
            $row['last_post_id'] = $cursor;
        }
        if ($title !== null && $title !== '') {
            $row['title'] = $title;
        }
        $this->db->update('sources', $row, ['id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->update('sources', [
            'status' => Source::STATUS_ERROR,
            'last_error' => mb_substr($error, 0, 1000),
            'last_parsed_at' => date('c'),
        ], ['id' => $id]);
    }
}
