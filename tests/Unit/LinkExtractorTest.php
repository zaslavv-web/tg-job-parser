<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Unit;

use TgJobParser\Links\LinkExtractor;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Tests\AppFactory;
use TgJobParser\Tests\TestCase;

/** ТЗ, раздел 6: приоритет ссылок. */
final class LinkExtractorTest extends TestCase
{
    private LinkExtractor $extractor;
    private Source $source;

    public function setUp(): void
    {
        $this->extractor = AppFactory::create()->get(LinkExtractor::class);
        $this->source = new Source(1, 'telegram', 'product_jobs');
    }

    private function extract(string $text, array $links = [], array $buttons = []): array
    {
        return $this->extractor->extract(new RawPost('7', $text, 'https://t.me/product_jobs/7', null, $links, $buttons), $this->source);
    }

    public function testJobBoardBeatsFormsCareersAndContacts(): void
    {
        $r = $this->extract('Пишите @hr_anna', ['https://acme.com/careers/pm', 'https://forms.gle/x', 'https://hh.ru/vacancy/1']);
        $this->assertSame('https://hh.ru/vacancy/1', $r['url']);
        $this->assertSame('job_board', $r['kind']);
    }

    public function testFormBeatsCareersPage(): void
    {
        $r = $this->extract('', ['https://acme.com/jobs/1'], [['label' => 'Откликнуться', 'url' => 'https://acme.typeform.com/to/abc']]);
        $this->assertSame('application_form', $r['kind']);
    }

    public function testCareersPageBeatsTelegramContact(): void
    {
        $r = $this->extract('Контакт: @hr_anna', ['https://acme.com/careers']);
        $this->assertSame('https://acme.com/careers', $r['url']);
    }

    public function testTelegramContactAsFallback(): void
    {
        $r = $this->extract('Резюме присылайте @hr_anna', ['https://example.com/blog']);
        $this->assertSame('https://t.me/hr_anna', $r['url']);
        $this->assertSame('telegram_contact', $r['kind']);
    }

    public function testPostLinkWhenNothingElse(): void
    {
        $r = $this->extract('Подробности в комментариях @product_jobs', ['https://t.me/product_jobs/6']);
        $this->assertSame('https://t.me/product_jobs/7', $r['url'], 'ссылки на сам канал не считаются');
        $this->assertSame('post', $r['kind']);
    }

    public function testLinkedinAndHabrCareer(): void
    {
        $this->assertSame('job_board', $this->extractor->classify('https://www.linkedin.com/jobs/view/1')['label'] ?? null);
        $this->assertSame('job_board', $this->extractor->classify('https://career.habr.com/vacancies/1000')['label'] ?? null);
        $this->assertSame('job_board', $this->extractor->classify('https://careers.google.com/x')['label'] ?? null);
    }
}
