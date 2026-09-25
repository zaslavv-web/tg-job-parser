<?php

declare(strict_types=1);

namespace TgJobParser\Source\Driver;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Model\FetchResult;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Source\SourceDriverInterface;

/** RSS 2.0 / Atom-ленты вакансий (многие job-сайты их отдают). */
final class RssDriver implements SourceDriverInterface
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function label(): string
    {
        return 'RSS / Atom-лента';
    }

    public function inputHint(): string
    {
        return 'https://site.ru/vacancies.rss';
    }

    public function supports(string $input): bool
    {
        return (bool) preg_match('~^https?://\S+(rss|atom|feed|\.xml)(\W|$)~i', trim($input));
    }

    public function describe(string $input, array $options = []): Source
    {
        $url = trim($input);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
            throw new InvalidArgumentException('Ожидается URL RSS-ленты');
        }

        return new Source(null, 'rss', $url, $url, parse_url($url, PHP_URL_HOST) ?: null, [
            'assume_vacancy' => (bool) ($options['assume_vacancy'] ?? true),
        ]);
    }

    public function fetch(Source $source, FetchOptions $options): FetchResult
    {
        $response = $this->http->get((string) $source->url, ['Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml']);
        if (!$response->ok()) {
            throw new RuntimeException("Лента вернула HTTP {$response->status}");
        }

        return self::parse($response->body, $options);
    }

    public static function parse(string $xml, ?FetchOptions $options = null): FetchResult
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($feed === false) {
            throw new RuntimeException('Ответ не является корректной RSS/Atom-лентой');
        }
        $posts = [];
        $title = null;
        if (isset($feed->channel)) {
            $title = trim((string) $feed->channel->title) ?: null;
            foreach ($feed->channel->item as $item) {
                $link = trim((string) $item->link);
                $posts[] = self::post(
                    trim((string) ($item->guid ?: $link)),
                    trim((string) $item->title),
                    (string) $item->description,
                    $link,
                    (string) $item->pubDate,
                );
            }
        } else {
            $title = trim((string) $feed->title) ?: null;
            foreach ($feed->entry as $entry) {
                $link = '';
                foreach ($entry->link as $l) {
                    if ((string) ($l['rel'] ?? 'alternate') === 'alternate') {
                        $link = (string) $l['href'];
                    }
                }
                $posts[] = self::post(
                    trim((string) ($entry->id ?: $link)),
                    trim((string) $entry->title),
                    (string) ($entry->content ?: $entry->summary),
                    $link,
                    (string) ($entry->published ?: $entry->updated),
                );
            }
        }
        $posts = array_values(array_filter($posts, static function (RawPost $p) use ($options): bool {
            return $options?->notBefore === null || $p->publishedAt === null || $p->publishedAt >= $options->notBefore;
        }));

        return new FetchResult($posts, date('c'), $title);
    }

    private static function post(string $id, string $title, string $html, string $link, string $date): RawPost
    {
        $body = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('~href=["\'](https?://[^"\']+)~i', $html, $m);
        try {
            $published = $date !== '' ? new DateTimeImmutable($date) : null;
        } catch (\Exception) {
            $published = null;
        }

        return new RawPost(
            externalId: $id !== '' ? mb_substr($id, 0, 250) : sha1($title . $link),
            text: trim($title . "\n\n" . trim($body)),
            url: $link ?: null,
            publishedAt: $published,
            links: array_values(array_unique(array_filter(array_merge([$link], $m[1] ?? [])))),
        );
    }

    public function unavailableReason(): ?string
    {
        return function_exists('simplexml_load_string') ? null : 'Нужно расширение ext-simplexml';
    }
}
