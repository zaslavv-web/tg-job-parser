<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Integration;

use TgJobParser\Application\FileLock;
use TgJobParser\Application\ParseService;
use TgJobParser\Application\RescoreService;
use TgJobParser\Application\SourceService;
use TgJobParser\Database\Database;
use TgJobParser\Http\HttpException;
use TgJobParser\Kernel\App;
use TgJobParser\Model\Post;
use TgJobParser\Profile\ProfileProvider;
use TgJobParser\Repository\LetterRepository;
use TgJobParser\Repository\PostRepository;
use TgJobParser\Repository\SourceRepository;
use TgJobParser\Tests\AppFactory;
use TgJobParser\Tests\FakeHttpClient;
use TgJobParser\Tests\TestCase;

/** Сквозной сценарий: источники → парсинг → классификация → письма, с отказами. */
final class ParseFlowTest extends TestCase
{
    private App $app;
    private FakeHttpClient $http;

    public function setUp(): void
    {
        $this->http = (new FakeHttpClient())
            ->on('https://t.me/s/product_jobs', self::fixture('telegram_page1.html'))
            ->on('https://t.me/s/product_jobs?before=101', self::fixture('telegram_page2.html'))
            ->on('https://t.me/s/broken_channel', new HttpException('Connection reset'))
            ->on('https://jobs.example.com/feed.rss', self::fixture('rss.xml'));
        $this->app = AppFactory::create($this->http, ['parsing.max_pages_first_run' => 2]);
        $sources = $this->app->get(SourceService::class);
        $sources->add('@product_jobs');
        $sources->add('@broken_channel');
        $sources->add('https://jobs.example.com/feed.rss');
    }

    public function testFullRunIsolatesFailuresAndProducesVacanciesWithLetters(): void
    {
        $report = $this->app->get(ParseService::class)->run('test');

        $this->assertSame(2, $report->sourcesOk);
        $this->assertSame(1, $report->sourcesFailed);
        $this->assertContains('Connection reset', $report->errors['broken_channel'] ?? '');
        $this->assertSame(9, $report->postsNew, '7 постов канала + 2 из RSS');

        $sources = $this->app->get(SourceRepository::class);
        $broken = $sources->findByHandle('telegram', 'broken_channel');
        $this->assertSame('error', $broken?->status);
        $ok = $sources->findByHandle('telegram', 'product_jobs');
        $this->assertSame('active', $ok?->status);
        $this->assertSame('104', $ok?->lastPostId);
        $this->assertSame('Product Jobs', $ok?->title);

        $posts = $this->app->get(PostRepository::class);
        $byExternal = [];
        foreach ($posts->search(['limit' => 100]) as $post) {
            $byExternal[$post->get('external_id')] = $post;
        }
        $this->assertSame('recommended', $byExternal['101']->status());
        $this->assertSame('https://hh.ru/vacancy/123456', $byExternal['101']->get('vacancy_url'));
        $this->assertSame('Acme', $byExternal['101']->get('company'));
        $this->assertSame('not_vacancy', $byExternal['102']->status());
        $this->assertSame('rejected', $byExternal['103']->status());
        $this->assertSame('rejected', $byExternal['104']->status());
        $this->assertSame('rejected', $byExternal['99']->status());
        $this->assertTrue($byExternal['98']->isShortlisted(), 'Алматы/Казахстан допустимы: ' . $byExternal['98']->get('reject_reason'));
        $this->assertSame('https://careers.kaspi.kz/jobs/42', $byExternal['98']->get('vacancy_url'));
        $this->assertTrue($byExternal['https://jobs.example.com/vacancy/777']->isShortlisted());
        $this->assertSame('rejected', $byExternal['https://jobs.example.com/vacancy/778']->status());

        $letters = $this->app->get(LetterRepository::class);
        $this->assertTrue($letters->exists($byExternal['101']->id()), 'письмо сгенерировано автоматически');
        $this->assertFalse($letters->exists($byExternal['103']->id()), 'для отклонённых писем нет');
        $this->assertSame($report->vacanciesFound, count($report->newShortlisted));
    }

    public function testSecondRunIsIdempotent(): void
    {
        $parser = $this->app->get(ParseService::class);
        $parser->run('test');
        $count = (int) $this->app->get(Database::class)->scalar('SELECT COUNT(*) FROM posts');
        $letters = (int) $this->app->get(Database::class)->scalar('SELECT COUNT(*) FROM cover_letters');

        $report = $parser->run('test');
        $this->assertSame(0, $report->postsNew);
        $this->assertSame($count, (int) $this->app->get(Database::class)->scalar('SELECT COUNT(*) FROM posts'));
        $this->assertSame($letters, (int) $this->app->get(Database::class)->scalar('SELECT COUNT(*) FROM cover_letters'));
    }

    public function testConcurrentRunIsSkipped(): void
    {
        $lock = new FileLock((string) $this->app->config->get('paths.lock'));
        $this->assertTrue($lock->acquire());
        try {
            $report = $this->app->get(ParseService::class)->run('test');
            $this->assertTrue($report->skipped);
            $this->assertSame(0, (int) $this->app->get(Database::class)->scalar('SELECT COUNT(*) FROM posts'));
        } finally {
            $lock->release();
        }
    }

    public function testManualDecisionSurvivesRescoreAndRuleChanges(): void
    {
        $this->app->get(ParseService::class)->run('test');
        $posts = $this->app->get(PostRepository::class);
        $junior = $posts->search(['statuses' => ['rejected'], 'limit' => 100]);
        $target = null;
        foreach ($junior as $p) {
            if ($p->get('external_id') === '103') {
                $target = $p;
            }
        }
        $this->assertTrue($target !== null);
        $posts->setManualStatus($target->id(), Post::STATUS_MAYBE);

        $this->app->get(ProfileProvider::class)->removeFromList('anti_roles', 'Junior / Middle');
        $stats = $this->app->get(RescoreService::class)->rescore();
        $this->assertSame(9, $stats['processed']);
        $after = $posts->find($target->id());
        $this->assertSame(Post::STATUS_MAYBE, $after?->status(), 'ручное решение важнее автоматики');
        $this->assertTrue($after?->get('reject_reason') === null || !str_contains((string) $after->get('reject_reason'), 'Junior'), 'правило снято → причина пересчитана');
    }

    public function testDeletingSourceRemovesItsPosts(): void
    {
        $this->app->get(ParseService::class)->run('test');
        $source = $this->app->get(SourceRepository::class)->findByHandle('telegram', 'product_jobs');
        $this->app->get(SourceService::class)->remove((int) $source?->id);
        $this->assertSame(2, (int) $this->app->get(Database::class)->scalar('SELECT COUNT(*) FROM posts'));
    }

    public function testDuplicateSourceIsRejected(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->app->get(SourceService::class)->add('https://t.me/product_jobs'));
    }
}
