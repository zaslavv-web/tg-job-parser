<?php

declare(strict_types=1);

namespace TgJobParser\Source\Driver;

use Closure;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;
use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Kernel\Config;
use TgJobParser\Model\FetchResult;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Source\SourceDriverInterface;

/**
 * Поиск вакансий LinkedIn через публичную (гостевую) выдачу — без логина и без риска для аккаунта.
 * Источник = один поиск: ссылка вида https://www.linkedin.com/jobs/search/?keywords=…&location=…&f_WT=2.
 * Дальше вакансии идут по тому же конвейеру фильтров и писем, что и посты из Telegram.
 *
 * Бережём лимиты LinkedIn: пауза между запросами, ограниченное число страниц и карточек,
 * автопарсинг поиска не чаще linkedin.min_interval_minutes.
 */
final class LinkedInDriver implements SourceDriverInterface
{
    private const SEARCH_PATH = '/jobs/search';
    private const GUEST_SEARCH = 'https://www.linkedin.com/jobs-guest/jobs/api/seeMoreJobPostings/search';
    private const GUEST_POSTING = 'https://www.linkedin.com/jobs-guest/jobs/api/jobPosting/';

    /** Параметры поиска LinkedIn, которые сохраняем (остальное — трекинг) */
    private const KEEP_PARAMS = ['keywords', 'location', 'geoId', 'f_WT', 'f_E', 'f_TPR', 'f_JT', 'f_SB2', 'f_C', 'f_I', 'distance', 'sortBy'];
    private const WORKPLACE = ['1' => 'On-site', '2' => 'Remote', '3' => 'Hybrid'];
    private const EXPERIENCE = ['1' => 'Internship', '2' => 'Entry', '3' => 'Associate', '4' => 'Mid-Senior', '5' => 'Director', '6' => 'Executive'];

    /** @var Closure(int): void пауза между запросами в мс (в тестах — без пауз) */
    private Closure $pause;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Config $config,
        ?callable $pause = null,
    ) {
        $this->pause = $pause !== null ? Closure::fromCallable($pause) : static fn (int $ms) => usleep($ms * 1000);
    }

    public function label(): string
    {
        return 'LinkedIn (поиск вакансий)';
    }

    public function inputHint(): string
    {
        return 'Ссылка на поиск: https://www.linkedin.com/jobs/search/?keywords=…';
    }

    public function supports(string $input): bool
    {
        $host = strtolower((string) parse_url(trim($input), PHP_URL_HOST));

        return (bool) preg_match('~(^|\.)linkedin\.com$~', $host)
            && str_starts_with((string) parse_url(trim($input), PHP_URL_PATH), '/jobs');
    }

    public function describe(string $input, array $options = []): Source
    {
        $params = self::searchParams(trim($input));
        $url = self::searchUrl($params);

        return new Source(null, 'linkedin', $url, $url, self::title($params), [
            'assume_vacancy' => true,
            'min_interval_minutes' => (int) $this->config->get('linkedin.min_interval_minutes', 120),
        ]);
    }

    public function fetch(Source $source, FetchOptions $options): FetchResult
    {
        $params = self::searchParams((string) $source->url);
        $maxPages = max(1, (int) $this->config->get('linkedin.max_pages', 2));
        $delay = (int) $this->config->get('linkedin.request_delay_ms', 1500);

        $cards = [];
        $limited = null;
        $offset = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            if ($page > 0) {
                ($this->pause)($delay);
            }
            // Размер страницы гостевой выдачи плавает (10–25) — смещаемся на фактическое число карточек
            $response = $this->http->get(self::GUEST_SEARCH . '?' . http_build_query($params + ['start' => $offset]), $this->headers());
            if (in_array($response->status, [429, 999], true)) {
                $limited = $response->status;
                break;
            }
            if (!$response->ok()) {
                throw new RuntimeException("LinkedIn вернул HTTP {$response->status}");
            }
            $found = self::parseSearch($response->body);
            $before = count($cards);
            foreach ($found as $card) {
                $cards[$card['id']] ??= $card;
            }
            if ($found === [] || count($cards) === $before) {
                break; // конец выдачи или LinkedIn вернул ту же страницу
            }
            $offset += count($found);
        }
        if ($cards === [] && $limited !== null) {
            throw new RuntimeException("LinkedIn временно ограничил запросы (HTTP {$limited}) — попробуем при следующем автопарсинге");
        }

        $workplace = self::single($params['f_WT'] ?? null, self::WORKPLACE);
        $budget = min($options->maxDetailPages, (int) $this->config->get('linkedin.max_details', 15));
        $posts = [];
        foreach ($cards as $card) {
            $details = null;
            if ($budget > 0 && $limited === null && !$options->isKnown($card['id'])) {
                $budget--;
                ($this->pause)($delay);
                try {
                    $response = $this->http->get(self::GUEST_POSTING . $card['id'], $this->headers());
                    if (in_array($response->status, [429, 999], true)) {
                        $limited = $response->status;
                    } elseif ($response->ok()) {
                        $details = self::parsePosting($response->body);
                    }
                } catch (\Throwable) {
                    // Без описания вакансия всё равно попадёт в конвейер — по заголовку и локации
                }
            }
            $posts[] = self::toPost($card, $details, $workplace);
        }

        return new FetchResult($posts, date('c'));
    }

    public function unavailableReason(): ?string
    {
        return function_exists('curl_init') ? null : 'Нужно расширение ext-curl';
    }

    /**
     * Карточки из гостевой выдачи поиска.
     *
     * @return list<array{id: string, title: string, company: ?string, location: ?string, salary: ?string, date: ?string}>
     */
    public static function parseSearch(string $html): array
    {
        $xpath = self::xpath($html);
        $cards = [];
        foreach ($xpath->query('//*[@data-entity-urn] | //*[contains(@class, "job-search-card")]') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $id = null;
            if (preg_match('~jobPosting:(\d+)~', $node->getAttribute('data-entity-urn'), $m)) {
                $id = $m[1];
            } else {
                $link = $xpath->query('.//a[contains(@href, "/jobs/view/")]', $node)->item(0);
                if ($link instanceof DOMElement && preg_match('~/jobs/view/(?:[^/?]*-)?(\d{6,})~', $link->getAttribute('href'), $m)) {
                    $id = $m[1];
                }
            }
            $title = self::text($xpath, './/*[contains(@class, "base-search-card__title")]', $node);
            if ($id === null || $title === '' || isset($cards[$id])) {
                continue;
            }
            $time = $xpath->query('.//time[@datetime]', $node)->item(0);
            $cards[$id] = [
                'id' => $id,
                'title' => $title,
                'company' => self::text($xpath, './/*[contains(@class, "base-search-card__subtitle")]', $node) ?: null,
                'location' => self::text($xpath, './/*[contains(@class, "job-search-card__location")]', $node) ?: null,
                'salary' => self::text($xpath, './/*[contains(@class, "job-search-card__salary-info")]', $node) ?: null,
                'date' => $time instanceof DOMElement ? $time->getAttribute('datetime') : null,
            ];
        }

        return array_values($cards);
    }

    /**
     * Описание и критерии со страницы вакансии.
     *
     * @return array{description: string, criteria: array<string, string>, location: ?string, apply_url: ?string}
     */
    public static function parsePosting(string $html): array
    {
        $xpath = self::xpath($html);
        $descriptionNode = $xpath->query('//*[contains(@class, "show-more-less-html__markup")] | //*[contains(@class, "description__text")]')->item(0);
        $description = '';
        if ($descriptionNode !== null) {
            $doc = new HtmlDocument('<html><body>' . $descriptionNode->ownerDocument->saveHTML($descriptionNode) . '</body></html>', 'https://www.linkedin.com/');
            $description = $doc->text($doc->xpath->query('//body')->item(0));
        }
        $criteria = [];
        foreach ($xpath->query('//*[contains(@class, "description__job-criteria-item")]') as $item) {
            $name = self::text($xpath, './/*[contains(@class, "description__job-criteria-subheader")]', $item);
            $value = self::text($xpath, './/*[contains(@class, "description__job-criteria-text")]', $item);
            if ($name !== '' && $value !== '') {
                $criteria[$name] = $value;
            }
        }
        $apply = $xpath->query('//a[contains(@class, "apply-button") and @href]')->item(0);

        return [
            'description' => $description,
            'criteria' => $criteria,
            'location' => self::text($xpath, '//*[contains(@class, "topcard__flavor--bullet")]') ?: null,
            'apply_url' => $apply instanceof DOMElement && str_starts_with($apply->getAttribute('href'), 'http') ? $apply->getAttribute('href') : null,
        ];
    }

    /**
     * Ссылка на поиск LinkedIn → нормализованные параметры.
     *
     * @return array<string, string>
     */
    public static function searchParams(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!preg_match('~(^|\.)linkedin\.com$~', $host) || !str_starts_with($path, '/jobs')) {
            throw new InvalidArgumentException('Ожидается ссылка на поиск вакансий LinkedIn (linkedin.com/jobs/search/?keywords=…)');
        }
        if (str_starts_with($path, '/jobs/view')) {
            throw new InvalidArgumentException('Это ссылка на одну вакансию. Нужна ссылка на поиск: откройте поиск вакансий LinkedIn, задайте фильтры и скопируйте адрес страницы.');
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $params = [];
        foreach (self::KEEP_PARAMS as $key) {
            if (isset($query[$key]) && is_scalar($query[$key]) && trim((string) $query[$key]) !== '') {
                $params[$key] = trim((string) $query[$key]);
            }
        }
        if (!isset($params['keywords']) && !isset($params['f_C'])) {
            throw new InvalidArgumentException('В поиске LinkedIn нет ключевых слов (keywords)');
        }
        $params['sortBy'] ??= 'DD'; // сначала новые
        ksort($params);

        return $params;
    }

    /** @param array<string, string> $params */
    public static function searchUrl(array $params): string
    {
        return 'https://www.linkedin.com' . self::SEARCH_PATH . '/?' . http_build_query($params);
    }

    /** @param array<string, string> $params */
    public static function title(array $params): string
    {
        $parts = [mb_strimwidth((string) ($params['keywords'] ?? 'компании'), 0, 70, '…')];
        if (!empty($params['location'])) {
            $parts[] = $params['location'];
        }
        $workplace = array_filter(array_map(static fn (string $c): ?string => self::WORKPLACE[$c] ?? null, explode(',', (string) ($params['f_WT'] ?? ''))));
        if ($workplace) {
            $parts[] = implode('/', $workplace);
        }
        $experience = array_filter(array_map(static fn (string $c): ?string => self::EXPERIENCE[$c] ?? null, explode(',', (string) ($params['f_E'] ?? ''))));
        if ($experience) {
            $parts[] = implode('/', $experience);
        }

        return 'LinkedIn: ' . implode(' · ', $parts);
    }

    /**
     * Текст для конвейера: заголовок первой строкой (по нему проверяются должности),
     * затем компания, локация, формат и описание — так работают те же фильтры, что и для Telegram.
     *
     * @param array{id: string, title: string, company: ?string, location: ?string, salary: ?string, date: ?string} $card
     * @param array{description: string, criteria: array<string, string>, location: ?string, apply_url: ?string}|null $details
     */
    private static function toPost(array $card, ?array $details, ?string $workplace): RawPost
    {
        $lines = [$card['title']];
        if ($card['company']) {
            $lines[] = 'Company: ' . $card['company'];
        }
        $location = $details['location'] ?? $card['location'];
        if ($location) {
            $lines[] = 'Location: ' . $location;
        }
        if ($workplace !== null) {
            $lines[] = 'Workplace: ' . $workplace;
        }
        if ($card['salary']) {
            $lines[] = 'Salary: ' . $card['salary'];
        }
        foreach ($details['criteria'] ?? [] as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        if (!empty($details['description'])) {
            $lines[] = '';
            $lines[] = mb_substr($details['description'], 0, 8000);
        }
        $url = 'https://www.linkedin.com/jobs/view/' . $card['id'] . '/';
        try {
            $date = $card['date'] ? new \DateTimeImmutable($card['date']) : null;
        } catch (\Exception) {
            $date = null;
        }

        return new RawPost(
            externalId: $card['id'],
            text: implode("\n", $lines),
            url: $url,
            publishedAt: $date,
            links: array_values(array_filter([$url, $details['apply_url'] ?? null])),
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'User-Agent' => (string) $this->config->get('linkedin.user_agent'),
            'Accept-Language' => 'en-US,en;q=0.9,ru;q=0.8',
            'Accept' => 'text/html,application/xhtml+xml',
        ];
    }

    /** Значение фильтра, если выбрано ровно одно (иначе формат не подставляем). */
    private static function single(?string $value, array $map): ?string
    {
        return $value !== null && !str_contains($value, ',') ? ($map[$value] ?? null) : null;
    }

    private static function xpath(string $html): DOMXPath
    {
        return (new HtmlDocument($html, 'https://www.linkedin.com/'))->xpath;
    }

    private static function text(DOMXPath $xpath, string $query, ?\DOMNode $context = null): string
    {
        $node = $xpath->query($query, $context)->item(0);

        return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
