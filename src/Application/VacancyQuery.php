<?php

declare(strict_types=1);

namespace TgJobParser\Application;

use TgJobParser\Model\Post;
use TgJobParser\Repository\LetterRepository;
use TgJobParser\Repository\PostRepository;

/** Выборки для UI/API: фильтры панели (ТЗ, раздел 5) → список вакансий с письмами. */
final class VacancyQuery
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly LetterRepository $letters,
    ) {
    }

    /**
     * @param array{show?: list<string>, period?: string, min_score?: int, source_id?: ?int} $filters
     * @return array{items: list<array{post: Post, letter: ?array<string, mixed>}>, counts: array<string, int>}
     */
    public function shortlist(array $filters): array
    {
        $show = array_values(array_intersect($filters['show'] ?? [Post::STATUS_RECOMMENDED, Post::STATUS_MAYBE], [Post::STATUS_RECOMMENDED, Post::STATUS_MAYBE]));
        $since = self::since($filters['period'] ?? 'all');
        $posts = $show === [] ? [] : $this->posts->search([
            'statuses' => $show,
            'since' => $since,
            'min_score' => $filters['min_score'] ?? null,
            'source_id' => $filters['source_id'] ?? null,
        ]);
        $letters = $this->letters->latestForMany(array_map(static fn (Post $p): int => $p->id(), $posts));
        $items = array_map(static fn (Post $p): array => ['post' => $p, 'letter' => $letters[$p->id()] ?? null], $posts);

        return ['items' => $items, 'counts' => $this->posts->countByStatus($since)];
    }

    /** @return list<Post> */
    public function rejected(string $period = 'all', int $limit = 200): array
    {
        return $this->posts->search(['statuses' => [Post::STATUS_REJECTED], 'since' => self::since($period), 'limit' => $limit]);
    }

    public static function since(string $period): ?string
    {
        return match ($period) {
            'today' => date('Y-m-d 00:00:00'),
            'week' => date('Y-m-d H:i:s', strtotime('-7 days')),
            default => null,
        };
    }
}
