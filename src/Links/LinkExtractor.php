<?php

declare(strict_types=1);

namespace TgJobParser\Links;

use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;

/**
 * Ссылка на вакансию по приоритетам из config/pipeline.php (ТЗ 6):
 * job-борды → формы → /careers на сайте компании → Telegram-контакт → ссылка на сам пост.
 */
final class LinkExtractor
{
    /** @param list<array{label: string, priority: int, hosts: list<string>, paths: list<string>}> $strategies */
    public function __construct(private readonly array $strategies)
    {
    }

    /** @return array{url: ?string, kind: string} */
    public function extract(RawPost $post, Source $source): array
    {
        $best = null;
        foreach ($this->candidates($post, $source) as $url) {
            $strategy = $this->classify($url);
            if ($strategy === null) {
                continue;
            }
            if ($best === null || $strategy['priority'] > $best['priority']) {
                $best = ['url' => $url, 'kind' => $strategy['label'], 'priority' => $strategy['priority']];
            }
        }
        if ($best !== null) {
            return ['url' => $best['url'], 'kind' => $best['kind']];
        }

        return ['url' => $post->url, 'kind' => 'post'];
    }

    /** @return list<string> */
    private function candidates(RawPost $post, Source $source): array
    {
        $urls = $post->links;
        // Голые URL в тексте (не всегда оформлены ссылкой)
        if (preg_match_all('~https?://[^\s<>"«»()]+~iu', $post->text, $bare)) {
            foreach ($bare[0] as $url) {
                $urls[] = rtrim($url, '.,;:!?');
            }
        }
        foreach ($post->buttons as $button) {
            $urls[] = $button['url'];
        }
        // Telegram-упоминания @username в тексте — как fallback-контакт
        if (preg_match_all('/(?<![\w.\/])@([a-zA-Z][\w]{3,31})\b/', $post->text, $m)) {
            foreach ($m[1] as $username) {
                if (strcasecmp($username, ltrim($source->handle, '@')) !== 0) {
                    $urls[] = 'https://t.me/' . $username;
                }
            }
        }
        $clean = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '' || !preg_match('~^https?://~i', $url)) {
                continue;
            }
            // Ссылки на сам канал-источник и его посты не являются ссылкой на вакансию
            if ($this->isSelfLink($url, $source)) {
                continue;
            }
            $clean[] = $url;
        }

        return array_values(array_unique($clean));
    }

    /** @return array{label: string, priority: int}|null */
    public function classify(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);
        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach ($this->sortedStrategies() as $strategy) {
            foreach ($strategy['hosts'] as $regex) {
                if (@preg_match($regex, $host) === 1) {
                    return $strategy;
                }
            }
            foreach ($strategy['paths'] as $regex) {
                if (@preg_match($regex, $path) === 1) {
                    return $strategy;
                }
            }
        }

        return null;
    }

    private function isSelfLink(string $url, Source $source): bool
    {
        if (!in_array($source->kind, ['telegram', 'telethon'], true)) {
            return false;
        }
        $handle = strtolower(ltrim($source->handle, '@'));

        return (bool) preg_match('~^https?://(t|telegram)\.me/(s/)?' . preg_quote($handle, '~') . '(/|\?|$)~i', $url);
    }

    /** @return list<array{label: string, priority: int, hosts: list<string>, paths: list<string>}> */
    private function sortedStrategies(): array
    {
        $strategies = $this->strategies;
        usort($strategies, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $strategies;
    }
}
