<?php

declare(strict_types=1);

namespace TgJobParser\Source\Driver;

use DOMElement;
use InvalidArgumentException;
use RuntimeException;
use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Model\FetchResult;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Source\SourceDriverInterface;

/**
 * Произвольный сайт с вакансиями (ТЗ, раздел 6). Логика та же, что для Telegram:
 * страница → список объявлений → общий конвейер фильтров и писем.
 *
 * Опции источника (все необязательны):
 *   item_xpath    — XPath карточки вакансии на странице списка
 *   link_pattern  — regex для ссылок на вакансии (по умолчанию /vacanc|job|career|position|вакан/)
 *   fetch_details — открывать страницы вакансий за полным описанием (по умолчанию да)
 * Без настроек драйвер сначала ищет schema.org JobPosting (JSON-LD), затем ссылки по шаблону.
 */
final class WebPageDriver implements SourceDriverInterface
{
    private const DEFAULT_LINK_PATTERN = '~(vacanc|/jobs?/|/job-|career|position|opening|вакан)~iu';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function label(): string
    {
        return 'Сайт с вакансиями';
    }

    public function inputHint(): string
    {
        return 'https://company.com/careers';
    }

    public function supports(string $input): bool
    {
        return (bool) preg_match('~^https?://[^\s/]+\.[^\s/]+~i', trim($input));
    }

    public function describe(string $input, array $options = []): Source
    {
        $url = trim($input);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
            throw new InvalidArgumentException('Ожидается URL страницы с вакансиями');
        }
        foreach (['link_pattern'] as $regexOption) {
            if (!empty($options[$regexOption]) && @preg_match((string) $options[$regexOption], '') === false) {
                throw new InvalidArgumentException('Некорректное регулярное выражение в ' . $regexOption);
            }
        }

        return new Source(null, 'web', $url, $url, parse_url($url, PHP_URL_HOST) ?: null, array_filter([
            'assume_vacancy' => (bool) ($options['assume_vacancy'] ?? true),
            'item_xpath' => trim((string) ($options['item_xpath'] ?? '')) ?: null,
            'link_pattern' => trim((string) ($options['link_pattern'] ?? '')) ?: null,
            'fetch_details' => (bool) ($options['fetch_details'] ?? true),
        ], static fn ($v): bool => $v !== null));
    }

    public function fetch(Source $source, FetchOptions $options): FetchResult
    {
        $page = $this->load((string) $source->url);
        $posts = [];

        // 1) JSON-LD на самой странице (часто это уже страница вакансии или список с разметкой)
        foreach ($page->jobPostings() as $job) {
            $url = is_string($job['url'] ?? null) ? $page->absolute($job['url']) : (string) $source->url;
            $posts[] = $this->jobPost($job, $url);
        }

        // 2) Карточки по XPath или ссылки по шаблону
        if ($posts === []) {
            $posts = $source->option('item_xpath')
                ? $this->itemsByXpath($page, (string) $source->option('item_xpath'))
                : $this->itemsByLinks($page, (string) ($source->option('link_pattern') ?: self::DEFAULT_LINK_PATTERN), (string) $source->url);
        }

        // 3) Полное описание со страниц вакансий — только для новых
        if ($source->option('fetch_details', true)) {
            $budget = $options->maxDetailPages;
            foreach ($posts as $i => $post) {
                if ($budget <= 0 || $post->url === null || $post->url === $source->url || $options->isKnown($post->externalId)) {
                    continue;
                }
                $budget--;
                try {
                    $posts[$i] = $this->withDetails($post);
                } catch (\Throwable) {
                    // Деталь недоступна — остаёмся с карточкой из списка
                }
            }
        }

        return new FetchResult($posts, date('c'));
    }

    private function load(string $url): HtmlDocument
    {
        $response = $this->http->get($url, ['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'ru,en;q=0.8']);
        if (!$response->ok()) {
            throw new RuntimeException("Сайт вернул HTTP {$response->status}");
        }

        return new HtmlDocument($response->body, $response->url ?: $url);
    }

    /** @param array<string, mixed> $job */
    private function jobPost(array $job, string $url): RawPost
    {
        try {
            $published = isset($job['datePosted']) ? new \DateTimeImmutable((string) $job['datePosted']) : null;
        } catch (\Exception) {
            $published = null;
        }

        return new RawPost(
            externalId: mb_substr($url, 0, 250),
            text: HtmlDocument::jobPostingText($job),
            url: $url,
            publishedAt: $published,
            links: [$url],
        );
    }

    /** @return list<RawPost> */
    private function itemsByXpath(HtmlDocument $page, string $xpath): array
    {
        $nodes = @$page->xpath->query($xpath);
        if ($nodes === false) {
            throw new RuntimeException('Некорректный item_xpath: ' . $xpath);
        }
        $posts = [];
        foreach ($nodes as $node) {
            $text = $page->text($node);
            $links = $page->links($node);
            if ($text === '') {
                continue;
            }
            $url = $links[0] ?? null;
            $posts[] = new RawPost(mb_substr($url ?? sha1($text), 0, 250), $text, $url, null, $links);
        }

        return $posts;
    }

    /** @return list<RawPost> */
    private function itemsByLinks(HtmlDocument $page, string $pattern, string $pageUrl): array
    {
        $posts = [];
        $seen = [];
        foreach ($page->xpath->query('//a[@href]') as $a) {
            if (!$a instanceof DOMElement) {
                continue;
            }
            $url = $page->absolute($a->getAttribute('href'));
            $title = trim((string) preg_replace('/\s+/u', ' ', $a->textContent));
            if (!preg_match('~^https?://~i', $url) || $url === $pageUrl || isset($seen[$url]) || mb_strlen($title) < 5) {
                continue;
            }
            if (@preg_match($pattern, $url) !== 1) {
                continue;
            }
            $seen[$url] = true;
            // Контекст карточки: ближайший блочный предок с разумным объёмом текста
            $container = $a;
            for ($depth = 0; $depth < 4 && $container->parentNode instanceof DOMElement; $depth++) {
                $parent = $container->parentNode;
                if (mb_strlen($page->text($parent)) > 600) {
                    break;
                }
                $container = $parent;
            }
            $text = $page->text($container);
            if (!str_contains($text, $title)) {
                $text = $title . "\n" . $text;
            }
            $posts[] = new RawPost(mb_substr($url, 0, 250), $text, $url, null, [$url]);
        }

        return $posts;
    }

    private function withDetails(RawPost $post): RawPost
    {
        $page = $this->load((string) $post->url);
        foreach ($page->jobPostings() as $job) {
            $detailed = $this->jobPost($job, (string) $post->url);

            return new RawPost($post->externalId, $detailed->text, $post->url, $detailed->publishedAt, $post->links);
        }
        $main = $page->xpath->query('//main | //article | //*[@role="main"]')->item(0)
            ?? $page->xpath->query('//body')->item(0);
        $text = mb_substr($page->text($main), 0, 8000);
        if (mb_strlen($text) < mb_strlen($post->text)) {
            return $post;
        }

        return new RawPost($post->externalId, $text, $post->url, $post->publishedAt, array_values(array_unique(array_merge($post->links, $main ? array_slice($page->links($main), 0, 30) : []))));
    }

    public function unavailableReason(): ?string
    {
        return function_exists('curl_init') ? null : 'Нужно расширение ext-curl';
    }
}
