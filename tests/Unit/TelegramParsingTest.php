<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Unit;

use TgJobParser\Http\HttpResponse;
use TgJobParser\Model\Source;
use TgJobParser\Source\Driver\TelegramHtmlParser;
use TgJobParser\Source\Driver\TelegramPublicDriver;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Tests\FakeHttpClient;
use TgJobParser\Tests\TestCase;

final class TelegramParsingTest extends TestCase
{
    public function testParsesTextLinksButtonsDateAndSkipsMediaWithoutCaption(): void
    {
        $result = (new TelegramHtmlParser())->parse(self::fixture('telegram_page1.html'), 'product_jobs');
        $this->assertSame('Product Jobs', $result['title']);
        $this->assertSame(['101', '102', '103', '104'], array_map(static fn ($p) => $p->externalId, $result['posts']));
        $this->assertSame(101, $result['oldest_id']);

        $post = $result['posts'][0];
        $this->assertContains("Head of Product в Acme\nB2B SaaS", $post->text, 'переносы строк сохраняются');
        $this->assertContains('P&L', $post->text);
        $this->assertSame('https://t.me/product_jobs/101', $post->url);
        $this->assertSame('2026-09-25 11:30', $post->publishedAt?->format('Y-m-d H:i'));
        $this->assertTrue(in_array('https://hh.ru/vacancy/123456', $post->links, true));
        $this->assertSame([['label' => 'Откликнуться', 'url' => 'https://forms.gle/abc123']], $post->buttons);
    }

    public function testUsernameNormalization(): void
    {
        foreach (['@product_jobs', 'https://t.me/product_jobs', 't.me/s/product_jobs', 'https://telegram.me/product_jobs/', 'product_jobs', 'https://t.me/product_jobs/123'] as $input) {
            $this->assertSame('product_jobs', TelegramPublicDriver::extractUsername($input), $input);
        }
        $this->assertSame(null, TelegramPublicDriver::extractUsername('https://t.me/+AbCdEf'));
        $this->assertSame(null, TelegramPublicDriver::extractUsername('https://hh.ru/vacancy/1'));
    }

    public function testFirstRunPaginatesBackAndIncrementalStopsAtCursor(): void
    {
        $http = (new FakeHttpClient())
            ->on('https://t.me/s/product_jobs', self::fixture('telegram_page1.html'))
            ->on('https://t.me/s/product_jobs?before=101', self::fixture('telegram_page2.html'))
            ->on('https://t.me/s/product_jobs?before=98', '<html><body><div class="tgme_channel_info"></div></body></html>');
        $driver = new TelegramPublicDriver($http, new TelegramHtmlParser());

        $first = $driver->fetch(new Source(1, 'telegram', 'product_jobs'), new FetchOptions(maxPages: 5, maxPagesFirstRun: 2));
        $this->assertSame(['98', '99', '100', '101', '102', '103', '104'], array_map(static fn ($p) => $p->externalId, $first->posts));
        $this->assertSame('104', $first->cursor);
        $this->assertSame(2, count($http->requests), 'на первом запуске — не больше maxPagesFirstRun страниц');

        $http->requests = [];
        $next = $driver->fetch(new Source(1, 'telegram', 'product_jobs', lastPostId: '102'), new FetchOptions());
        $this->assertSame(['103', '104'], array_map(static fn ($p) => $p->externalId, $next->posts));
        $this->assertSame(1, count($http->requests), 'дошли до известного поста — дальше не листаем');
    }

    public function testNoNewPostsKeepsCursor(): void
    {
        $http = (new FakeHttpClient())->on('https://t.me/s/product_jobs', self::fixture('telegram_page1.html'));
        $result = (new TelegramPublicDriver($http, new TelegramHtmlParser()))
            ->fetch(new Source(1, 'telegram', 'product_jobs', lastPostId: '104'), new FetchOptions());
        $this->assertSame([], $result->posts);
        $this->assertSame('104', $result->cursor);
    }

    public function testPrivateOrMissingChannelGivesClearError(): void
    {
        $http = (new FakeHttpClient())->on('https://t.me/s/secret_chan', self::fixture('telegram_empty.html'));
        $e = $this->assertThrows(\RuntimeException::class, fn () => (new TelegramPublicDriver($http, new TelegramHtmlParser()))
            ->fetch(new Source(1, 'telegram', 'secret_chan'), new FetchOptions()));
        $this->assertContains('Telethon', $e->getMessage());

        $http->on('https://t.me/s/blocked', new HttpResponse(429, 'Too Many Requests'));
        $e = $this->assertThrows(\RuntimeException::class, fn () => (new TelegramPublicDriver($http, new TelegramHtmlParser()))
            ->fetch(new Source(1, 'telegram', 'blocked'), new FetchOptions()));
        $this->assertContains('HTTP 429', $e->getMessage());
    }
}
