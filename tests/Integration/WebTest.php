<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Integration;

use TgJobParser\Application\ParseService;
use TgJobParser\Application\SourceService;
use TgJobParser\Kernel\App;
use TgJobParser\Repository\SourceRepository;
use TgJobParser\Tests\AppFactory;
use TgJobParser\Tests\FakeHttpClient;
use TgJobParser\Tests\TestCase;
use TgJobParser\Web\Request;
use TgJobParser\Web\Session;
use TgJobParser\Web\WebKernel;

final class WebTest extends TestCase
{
    private App $app;
    private WebKernel $kernel;
    private string $token;

    public function setUp(): void
    {
        $http = (new FakeHttpClient())
            ->on('https://t.me/s/product_jobs', self::fixture('telegram_page1.html'))
            ->on('~before=~', '<html><body><div class="tgme_channel_info"></div></body></html>');
        $this->app = AppFactory::create($http);
        $this->kernel = new WebKernel($this->app);
        $this->token = $this->app->get(Session::class)->csrfToken();
    }

    private function post(string $path, array $data): \TgJobParser\Web\Response
    {
        return $this->kernel->handle(new Request('POST', $path, [], $data + ['_token' => $this->token], ['HTTP_REFERER' => 'http://localhost/']));
    }

    public function testDashboardRendersAllSections(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/'));
        $this->assertSame(200, $response->status);
        foreach (['Каналы и источники', 'Фильтры', 'Вакансии', 'Лог отклонённых', 'Параметры поиска и писем', 'Проверить все каналы'] as $section) {
            $this->assertContains($section, $response->body);
        }
    }

    public function testAddSourceParseAndSeeVacancyCard(): void
    {
        $this->assertSame(303, $this->post('/sources', ['input' => '@product_jobs', 'kind' => 'auto'])->status);
        $this->assertSame(1, count($this->app->get(SourceRepository::class)->all()));
        $this->post('/parse', []);

        $page = $this->kernel->handle(new Request('GET', '/'))->body;
        $this->assertContains('[Скоринг: 88/100]', $page);
        $this->assertContains('✅ Рекомендуется', $page);
        $this->assertContains('Head of Product в Acme', $page);
        $this->assertContains('https://hh.ru/vacancy/123456', $page);
        $this->assertContains('Копировать письмо', $page);
        $this->assertContains('Откликаюсь на позицию', $page);
        $this->assertContains('Анти-должность: Junior / Middle', $page, 'лог отклонённых с причиной');
        $this->assertContains('Найдено: <b>1</b> подходящих', $page);
    }

    public function testPostWithoutCsrfIsRefused(): void
    {
        $response = $this->kernel->handle(new Request('POST', '/sources', [], ['input' => '@product_jobs']));
        $this->assertSame(303, $response->status);
        $this->assertSame(0, count($this->app->get(SourceRepository::class)->all()));
    }

    public function testValidationErrorsBecomeFlashMessages(): void
    {
        $this->post('/sources', ['input' => 'не ссылка и не канал']);
        $page = $this->kernel->handle(new Request('GET', '/'))->body;
        $this->assertContains('Не удалось определить тип источника', $page);
    }

    public function testSettingsAddItemAndApi(): void
    {
        $this->assertSame(303, $this->post('/settings/list/add', ['list' => 'vacancy_markers', 'value' => '#найм'])->status);
        $page = $this->kernel->handle(new Request('GET', '/'))->body;
        $this->assertContains('#найм', $page);

        $this->app->get(SourceService::class)->add('@product_jobs');
        $this->app->get(ParseService::class)->run('test');
        $api = $this->kernel->handle(new Request('GET', '/api/vacancies'));
        $data = json_decode($api->body, true);
        $this->assertSame(1, count($data['items']));
        $this->assertSame(88, $data['items'][0]['score']);
        $this->assertContains('Здравствуйте', $data['items'][0]['letter']['text']);

        $health = json_decode($this->kernel->handle(new Request('GET', '/api/health'))->body, true);
        $this->assertTrue($health['ok']);
    }

    public function testRejectRestoreAndRegenerateLetter(): void
    {
        $this->app->get(SourceService::class)->add('@product_jobs');
        $this->app->get(ParseService::class)->run('test');
        $data = json_decode($this->kernel->handle(new Request('GET', '/api/vacancies'))->body, true);
        $id = $data['items'][0]['id'];

        $this->post("/vacancies/{$id}/reject", []);
        $this->assertSame(0, count(json_decode($this->kernel->handle(new Request('GET', '/api/vacancies'))->body, true)['items']));
        $this->post("/vacancies/{$id}/restore", []);
        $this->assertSame(1, count(json_decode($this->kernel->handle(new Request('GET', '/api/vacancies'))->body, true)['items']));

        $json = $this->kernel->handle(new Request('POST', "/vacancies/{$id}/letter", [], ['_token' => $this->token, 'mode' => 'template', 'lang' => 'en'], ['HTTP_ACCEPT' => 'application/json']));
        $letter = json_decode($json->body, true)['letter'];
        $this->assertSame('en', $letter['language']);
        $this->assertContains("I'm applying", $letter['text']);
    }

    public function testBasicAuthWhenPasswordSet(): void
    {
        $app = AppFactory::create(null, ['web.password' => 's3cret']);
        $kernel = new WebKernel($app);
        $this->assertSame(401, $kernel->handle(new Request('GET', '/'))->status);
        $this->assertSame(200, $kernel->handle(new Request('GET', '/', [], [], ['PHP_AUTH_PW' => 's3cret']))->status);
    }
}
