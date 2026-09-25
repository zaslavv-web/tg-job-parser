<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Unit;

use TgJobParser\Model\Source;
use TgJobParser\Source\Driver\RssDriver;
use TgJobParser\Source\Driver\TelethonDriver;
use TgJobParser\Source\Driver\WebPageDriver;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Source\SourceRegistry;
use TgJobParser\Tests\AppFactory;
use TgJobParser\Tests\FakeHttpClient;
use TgJobParser\Tests\TestCase;

final class OtherSourcesTest extends TestCase
{
    public function testRssFeed(): void
    {
        $result = RssDriver::parse(self::fixture('rss.xml'));
        $this->assertSame('Вакансии: продакт-менеджмент', $result->title);
        $this->assertSame(2, count($result->posts));
        $post = $result->posts[0];
        $this->assertSame('https://jobs.example.com/vacancy/777', $post->externalId);
        $this->assertContains("Lead Product Manager (AI-платформа)\n\nКомпания: Neuro Labs", $post->text);
        $this->assertSame('https://jobs.example.com/vacancy/777', $post->url);
    }

    public function testAtomFeed(): void
    {
        $result = RssDriver::parse(self::fixture('atom.xml'));
        $this->assertSame('https://remote.example.org/jobs/1', $result->posts[0]->url);
        $this->assertContains('Fully remote', $result->posts[0]->text);
    }

    public function testInvalidFeedFailsLoudly(): void
    {
        $this->assertThrows(\RuntimeException::class, fn () => RssDriver::parse('<html>not a feed'));
    }

    public function testWebPageListWithDetailPagesFromJsonLd(): void
    {
        $http = (new FakeHttpClient())
            ->on('https://orbit.example/careers', self::fixture('web_list.html'))
            ->on('https://orbit.example/careers/head-of-product', self::fixture('web_detail.html'))
            ->on('https://orbit.example/careers/frontend-developer', '<html><body><main><h1>Frontend Developer</h1><p>Офис Москва, React.</p></main></body></html>');
        $driver = new WebPageDriver($http);
        $source = $driver->describe('https://orbit.example/careers');
        $source = new Source(5, $source->kind, $source->handle, $source->url, $source->title, $source->options);

        $result = $driver->fetch($source, new FetchOptions());
        $this->assertSame(2, count($result->posts), 'ссылки навигации (/about, /blog) не считаются вакансиями');
        $head = $result->posts[0];
        $this->assertSame('https://orbit.example/careers/head-of-product', $head->url);
        $this->assertContains('Компания: Orbit', $head->text);
        $this->assertContains('Remote', $head->text);
        $this->assertContains('монетизацию', $head->text);
        $this->assertSame('2026-09-22', $head->publishedAt?->format('Y-m-d'));
    }

    public function testWebPageSkipsDetailsOfKnownPosts(): void
    {
        $http = (new FakeHttpClient())->on('https://orbit.example/careers', self::fixture('web_list.html'));
        $driver = new WebPageDriver($http);
        $source = new Source(5, 'web', 'https://orbit.example/careers', 'https://orbit.example/careers', null, ['fetch_details' => true]);
        $result = $driver->fetch($source, new FetchOptions(isKnown: static fn (): bool => true));
        $this->assertSame(2, count($result->posts));
        $this->assertSame(1, count($http->requests), 'детали уже известных вакансий не скачиваются');
    }

    public function testWebPageWithCustomXpath(): void
    {
        $http = (new FakeHttpClient())->on('https://orbit.example/careers', self::fixture('web_list.html'));
        $driver = new WebPageDriver($http);
        $source = new Source(5, 'web', 'https://orbit.example/careers', 'https://orbit.example/careers', null, ['item_xpath' => "//li[@class='job']", 'fetch_details' => false]);
        $posts = $driver->fetch($source, new FetchOptions())->posts;
        $this->assertSame(2, count($posts));
        $this->assertContains('Head of Product', $posts[0]->text);
        $this->assertContains('Remote · B2B SaaS', $posts[0]->text);
        $this->assertSame('https://orbit.example/careers/head-of-product', $posts[0]->url);
    }

    public function testTelethonOutputContract(): void
    {
        $result = TelethonDriver::parseOutput([
            'title' => 'Private Jobs',
            'posts' => [
                ['id' => 12, 'text' => "#вакансия\nCPO", 'date' => '2026-09-25T10:00:00+00:00', 'url' => 'https://t.me/c/1/12', 'links' => ['https://hh.ru/vacancy/9'], 'buttons' => [['label' => 'Apply', 'url' => 'https://forms.gle/z']]],
                ['id' => 11, 'text' => ''],
            ],
        ], new Source(1, 'telethon', 'https://t.me/+abc'));
        $this->assertSame(1, count($result->posts));
        $this->assertSame('12', $result->cursor);
        $this->assertSame('https://forms.gle/z', $result->posts[0]->buttons[0]['url']);
    }

    public function testRegistryAutodetectsKind(): void
    {
        $registry = AppFactory::create()->get(SourceRegistry::class);
        $this->assertSame('telegram', $registry->describe('@product_jobs')->kind);
        $this->assertSame('telegram', $registry->describe('https://t.me/product_jobs')->kind);
        $this->assertSame('telethon', $registry->describe('https://t.me/+AbCdEf123')->kind);
        $this->assertSame('rss', $registry->describe('https://jobs.example.com/feed.xml')->kind);
        $this->assertSame('web', $registry->describe('https://company.com/careers')->kind);
        $this->assertSame('telethon', $registry->describe('@product_jobs', 'telethon')->kind, 'явный тип важнее автоопределения');
        $this->assertThrows(\InvalidArgumentException::class, fn () => $registry->describe('просто текст'));
    }
}
