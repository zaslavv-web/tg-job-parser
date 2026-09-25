<?php

declare(strict_types=1);

namespace TgJobParser\Repository;

use TgJobParser\Database\Database;
use TgJobParser\Letter\Letter;

final class LetterRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function save(int $postId, Letter $letter): int
    {
        return $this->db->insert('cover_letters', [
            'post_id' => $postId,
            'language' => $letter->language,
            'text' => $letter->text,
            'mode' => $letter->mode,
            'note' => $letter->note,
            'created_at' => date('c'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function latestFor(int $postId): ?array
    {
        return $this->db->one('SELECT * FROM cover_letters WHERE post_id = ? ORDER BY id DESC LIMIT 1', [$postId]);
    }

    /**
     * @param list<int> $postIds
     * @return array<int, array<string, mixed>> post_id → последнее письмо
     */
    public function latestForMany(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($postIds), '?'));
        $rows = $this->db->all(
            "SELECT * FROM cover_letters WHERE id IN (
                SELECT MAX(id) FROM cover_letters WHERE post_id IN ({$placeholders}) GROUP BY post_id
            )",
            $postIds,
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['post_id']] = $row;
        }

        return $result;
    }

    public function exists(int $postId): bool
    {
        return $this->db->scalar('SELECT 1 FROM cover_letters WHERE post_id = ? LIMIT 1', [$postId]) !== null;
    }
}
