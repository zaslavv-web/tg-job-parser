<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Unit;

use TgJobParser\Application\ParseService;
use TgJobParser\Application\SeedService;
use TgJobParser\Application\SourceService;
use TgJobParser\Http\HttpResponse;
use TgJobParser\Kernel\App;
use TgJobParser\Kernel\Config;
use TgJobParser\Letter\LetterService;
use TgJobParser\Model\Source;
use TgJobParser\Repository\PostRepository;
use TgJobParser\Repository\SourceRepository;
use TgJobParser\Source\Driver\LinkedInDriver;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Source\SourceRegistry;
use TgJobParser\Tests\AppFactory;
use TgJobParser\Tests\FakeHttpClient;
use TgJobParser\Tests\TestCase;

final class LinkedInTest extends TestCase
{
    private const SEARCH = 'https://www.linkedin.com/jobs/search/?keywords=Head%20of%20Product&location=Worldwide&f_WT=2&f_E=5%2C6&trk=public_jobs&currentJobId=1&refId=xyz';

    private FakeHttpClient $http;

    public function setUp(): void
    {
        $this->http = (new FakeHttpClient())
            ->on('~seeMoreJobPostings/search\?.*start=0~', self::fixture('linkedin_search.html'))
            ->on('~jobPosting/4012345678$~', self::fixture('linkedin_posting.html'))
            ->on('~jobPosting/\d+$~', '<html><body><div class="show-more-less-html__markup">Short description.</div></body></html>');
    }

    private function app(array $overrides = []): App
    {
        return AppFactory::create($this->http, $overrides + ['linkedin.request_delay_ms' => 0], [
            LinkedInDriver::class => fn ($c) => new LinkedInDriver($this->http, $c->get(Config::class), static fn () => null),
        ]);
    }

    public function testSearchCardsAreParsed(): void
    {
        $cards = LinkedInDriver::parseSearch(self::fixture('linkedin_search.html'));
        $this->assertSame(['4012345678', '4012345679', '4012345680'], array_column($cards, 'id'));
        $this->assertSame('Head of Product', $cards[0]['title']);
        $this->assertSame('Acme AI', $cards[0]['company']);
        $this->assertSame('United States', $cards[0]['location']);
        $this->assertSame('$180,000.00 - $220,000.00', $cards[0]['salary']);
        $this->assertSame('2026-10-12', $cards[0]['date']);
        $this->assertSame([], LinkedInDriver::parseSearch('<html><body>No jobs</body></html>'));
    }

    public function testPostingDetailsAreParsed(): void
    {
        $posting = LinkedInDriver::parsePosting(self::fixture('linkedin_posting.html'));
        $this->assertContains('B2B SaaS platform with AI agents', $posting['description']);
        $this->assertContains('Own the product strategy, P&L', $posting['description']);
        $this->assertSame('Director', $posting['criteria']['Seniority level']);
        $this->assertSame('https://boards.greenhouse.io/acmeai/jobs/12345', $posting['apply_url']);
    }

    public function testSearchUrlIsNormalizedAndValidated(): void
    {
        $source = $this->app()->get(SourceRegistry::class)->describe(self::SEARCH);
        $this->assertSame('linkedin', $source->kind, 'ссылка LinkedIn не уходит в общий драйвер сайтов');
        $this->assertNotContains('trk=', $source->handle, 'трекинговые параметры отброшены');
        $this->assertNotContains('currentJobId', $source->handle);
        $this->assertContains('sortBy=DD', $source->handle, 'сначала новые');
        $this->assertSame('LinkedIn: Head of Product · Worldwide · Remote · Director/Executive', $source->title);
        $this->assertTrue((bool) $source->option('assume_vacancy'));

        $registry = $this->app()->get(SourceRegistry::class);
        $e = $this->assertThrows(\InvalidArgumentException::class, fn () => $registry->describe('https://www.linkedin.com/jobs/view/head-of-product-4012345678'));
        $this->assertContains('ссылка на одну вакансию', $e->getMessage());
        $this->assertThrows(\InvalidArgumentException::class, fn () => $registry->describe('https://www.linkedin.com/jobs/search/?location=Worldwide'));
    }

    public function testFetchBuildsPipelineFriendlyTextAndFetchesDetailsOnlyForNewJobs(): void
    {
        $app = $this->app();
        $driver = $app->get(LinkedInDriver::class);
        $source = $driver->describe(self::SEARCH);
        $result = $driver->fetch($source, new FetchOptions(isKnown: static fn (string $id): bool => $id === '4012345680'));

        $this->assertSame(3, count($result->posts));
        $head = $result->posts[0];
        $this->assertSame('4012345678', $head->externalId);
        $this->assertSame('https://www.linkedin.com/jobs/view/4012345678/', $head->url);
        $this->assertTrue(str_starts_with($head->text, "Head of Product\nCompany: Acme AI\nLocation: United States\nWorkplace: Remote\nSalary: $180,000.00"));
        $this->assertContains('Seniority level: Director', $head->text);
        $this->assertContains('AI agents for HR teams', $head->text);
        $this->assertTrue(in_array('https://boards.greenhouse.io/acmeai/jobs/12345', $head->links, true));

        $detailRequests = array_filter($this->http->requests, static fn (array $r): bool => str_contains($r['url'], 'jobPosting/'));
        $this->assertSame(2, count($detailRequests), 'известная вакансия не скачивается повторно');
        $this->assertContains('start=0', $this->http->requests[0]['url']);
        $this->assertSame(1, count(array_filter($this->http->requests, static fn (array $r): bool => str_contains($r['url'], 'seeMore'))), 'неполная страница — дальше не листаем');
    }

    public function testRateLimitIsReportedClearlyOrReturnsPartialResult(): void
    {
        $limited = (new FakeHttpClient())->on('~seeMoreJobPostings~', new HttpResponse(429, 'Too Many Requests'));
        $app = AppFactory::create($limited);
        $driver = new LinkedInDriver($limited, $app->get(Config::class), static fn () => null);
        $e = $this->assertThrows(\RuntimeException::class, fn () => $driver->fetch($driver->describe(self::SEARCH), new FetchOptions()));
        $this->assertContains('ограничил запросы', $e->getMessage());

        $partial = (new FakeHttpClient())
            ->on('~seeMoreJobPostings~', self::fixture('linkedin_search.html'))
            ->on('~jobPosting/~', new HttpResponse(999, ''));
        $driver = new LinkedInDriver($partial, $app->get(Config::class), static fn () => null);
        $result = $driver->fetch($driver->describe(self::SEARCH), new FetchOptions());
        $this->assertSame(3, count($result->posts), 'карточки из поиска не теряются, даже если описания недоступны');
        $this->assertSame(1, count(array_filter($partial->requests, static fn (array $r): bool => str_contains($r['url'], 'jobPosting/'))), 'после ограничения детали больше не запрашиваем');
    }

    public function testLinkedInVacanciesGoThroughTheSamePipeline(): void
    {
        $app = $this->app();
        $app->get(SourceService::class)->add(self::SEARCH);
        $report = $app->get(ParseService::class)->run('manual');
        $this->assertSame(1, $report->sourcesOk);

        $byId = [];
        foreach ($app->get(PostRepository::class)->search(['limit' => 50]) as $post) {
            $byId[$post->get('external_id')] = $post;
        }
        $head = $byId['4012345678'];
        $this->assertSame('recommended', $head->status(), (string) $head->get('reject_reason'));
        $this->assertSame('Acme AI', $head->get('company'));
        $this->assertSame('en', $head->get('language'));
        $this->assertNotContains('Требуемый опыт', json_encode($head->reasons(), JSON_UNESCAPED_UNICODE), '«Within 1 year…» не штрафуется как опыт < 2 лет');
        $this->assertSame('rejected', $byId['4012345679']->status(), 'Junior — анти-должность');
        $this->assertSame('География: Великобритания', $byId['4012345680']->get('reject_reason'));

        $letter = $app->get(LetterService::class)->generateFor($head);
        $this->assertContains("I'm applying for the Head of Product role at Acme AI.", $letter->text);
        $this->assertContains('Vladimir Zaslav', $letter->text, 'подпись латиницей в английском письме');
        $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $letter->text), 'в английском письме нет кириллицы: ' . $letter->text);
    }

    public function testCronRespectsMinimumIntervalButManualRunDoesNot(): void
    {
        $app = $this->app();
        $app->get(SourceService::class)->add(self::SEARCH);
        $app->get(ParseService::class)->run('manual');
        $searches = static fn (FakeHttpClient $h): int => count(array_filter($h->requests, static fn (array $r): bool => str_contains($r['url'], 'seeMore')));
        $before = $searches($this->http);

        $app->get(ParseService::class)->run('cron');
        $this->assertSame($before, $searches($this->http), 'автопарсинг не дёргает LinkedIn раньше min_interval_minutes');
        $app->get(ParseService::class)->run('manual');
        $this->assertSame($before + 1, $searches($this->http), 'кнопка «Проверить все» работает всегда');
    }

    public function testRecommendedSearchesAreValidAndSeedingIsIdempotent(): void
    {
        $app = $this->app();
        $seeds = $app->get(SeedService::class);
        $linkedIn = array_values(array_filter($seeds->recommended(), static fn (string $i): bool => str_contains($i, 'linkedin.com')));
        $this->assertTrue(count($linkedIn) >= 5);
        $this->assertContains('f_WT=2', $linkedIn[0]);
        $this->assertContains('f_E=5%2C6', $linkedIn[0]);
        $this->assertContains('f_TPR=r604800', $linkedIn[0]);

        $first = $seeds->seed();
        $this->assertSame(count($seeds->recommended()), count($first['added']));
        $this->assertSame(0, count($seeds->seed()['added']), 'повторное подключение ничего не дублирует');
        $kinds = array_count_values(array_map(static fn (Source $s): string => $s->kind, $app->get(SourceRepository::class)->all()));
        $this->assertSame(count($linkedIn), $kinds['linkedin']);
        $this->assertTrue(($kinds['telegram'] ?? 0) >= 9);

        $this->assertThrows(\InvalidArgumentException::class, fn () => SeedService::linkedInUrl(['keywords' => 'PM', 'workplace' => 'office']));
    }
}
