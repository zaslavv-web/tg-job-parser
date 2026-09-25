<?php

declare(strict_types=1);

namespace TgJobParser\Source\Driver;

use InvalidArgumentException;
use RuntimeException;
use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Model\FetchResult;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Source\SourceDriverInterface;

/** Вариант A из ТЗ: публичные каналы через https://t.me/s/{username} + DOMDocument. */
final class TelegramPublicDriver implements SourceDriverInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly TelegramHtmlParser $parser,
    ) {
    }

    public function label(): string
    {
        return 'Telegram (публичный канал)';
    }

    public function inputHint(): string
    {
        return '@username или https://t.me/username';
    }

    public function supports(string $input): bool
    {
        return self::extractUsername($input) !== null;
    }

    public function describe(string $input, array $options = []): Source
    {
        $username = self::extractUsername($input) ?? throw new InvalidArgumentException('Ожидается @username или ссылка t.me/…');

        return new Source(null, 'telegram', $username, 'https://t.me/' . $username, null, []);
    }

    public function fetch(Source $source, FetchOptions $options): FetchResult
    {
        $username = $source->handle;
        $lastId = $source->lastPostId !== null ? (int) $source->lastPostId : null;
        $maxPages = $lastId === null ? $options->maxPagesFirstRun : $options->maxPages;

        $collected = [];
        $title = null;
        $before = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $url = 'https://t.me/s/' . rawurlencode($username) . ($before !== null ? '?before=' . $before : '');
            $response = $this->http->get($url, ['Accept-Language' => 'ru,en;q=0.8']);
            if (!$response->ok()) {
                throw new RuntimeException("t.me вернул HTTP {$response->status} для @{$username}");
            }
            $parsed = $this->parser->parse($response->body, $username);
            $title ??= $parsed['title'];
            if ($page === 0 && $parsed['oldest_id'] === null) {
                if (!str_contains($response->body, 'tgme_channel_info')) {
                    throw new RuntimeException("Канал @{$username} не найден или не публичный (нужен Telethon)");
                }
                break;
            }
            $reachedKnown = false;
            foreach ($parsed['posts'] as $post) {
                if ($lastId !== null && (int) $post->externalId <= $lastId) {
                    $reachedKnown = true;
                    continue;
                }
                if ($options->notBefore !== null && $post->publishedAt !== null && $post->publishedAt < $options->notBefore) {
                    $reachedKnown = true;
                    continue;
                }
                $collected[$post->externalId] = $post;
            }
            if ($reachedKnown || $parsed['oldest_id'] === null || $parsed['oldest_id'] <= 1) {
                break;
            }
            $before = $parsed['oldest_id'];
        }

        $posts = array_values($collected);
        usort($posts, static fn (RawPost $a, RawPost $b): int => (int) $a->externalId <=> (int) $b->externalId);
        $cursor = $posts ? end($posts)->externalId : $source->lastPostId;

        return new FetchResult($posts, $cursor, $title);
    }

    public function unavailableReason(): ?string
    {
        return function_exists('curl_init') ? null : 'Нужно расширение ext-curl';
    }

    public static function extractUsername(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('~^@([a-zA-Z][\w]{3,31})$~', $input, $m)) {
            return $m[1];
        }
        if (preg_match('~^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/(?:s/)?([a-zA-Z][\w]{3,31})/?(?:\d+)?/?(?:\?.*)?$~i', $input, $m)) {
            return $m[1];
        }
        if (preg_match('~^[a-zA-Z][\w]{3,31}$~', $input) && !str_contains($input, '.')) {
            return $input;
        }

        return null;
    }
}
