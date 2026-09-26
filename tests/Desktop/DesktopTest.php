<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Desktop;

use TgJobParser\Desktop\DataDir;
use TgJobParser\Desktop\HttpServer;
use TgJobParser\Kernel\App;
use TgJobParser\Kernel\EnvFile;
use TgJobParser\Tests\TestCase;
use TgJobParser\Web\Request;
use TgJobParser\Web\Session;
use TgJobParser\Web\WebKernel;

final class DesktopTest extends TestCase
{
    private string $data;
    private string $root;
    private Session $session;
    private HttpServer $server;

    public function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->data = sys_get_temp_dir() . '/tgjp-desktop-' . bin2hex(random_bytes(4));
        mkdir($this->data);
        $this->session = new Session(false);
        // Как в Launcher: приложение собирается заново на каждый запрос, сессия общая
        $this->server = new HttpServer(fn (Request $r) => (new WebKernel(App::boot($this->root, [], [Session::class => fn () => $this->session], $this->data)))->handle($r), $this->root . '/public');
    }

    public function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->data));
    }

    private function get(string $path, string $host = '127.0.0.1:8765'): string
    {
        return $this->server->handleRaw("GET {$path} HTTP/1.1\r\nHost: {$host}\r\n\r\n");
    }

    private function post(string $path, array $form): string
    {
        $body = http_build_query($form + ['_token' => $this->session->csrfToken()]);

        return $this->server->handleRaw("POST {$path} HTTP/1.1\r\nHost: 127.0.0.1:8765\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}");
    }

    public function testServesDashboardAndDataLivesInDataDir(): void
    {
        $raw = $this->get('/');
        $this->assertTrue(str_starts_with($raw, 'HTTP/1.1 200 OK'), strtok($raw, "\r\n"));
        $this->assertContains('Content-Length: ', $raw);
        $this->assertContains('Радар вакансий', $raw);
        $this->assertContains('Выключить программу', $raw);
        $this->assertTrue(is_file($this->data . '/db/database.sqlite'), 'БД в каталоге данных, а не рядом с кодом');
    }

    public function testStaticAssetsAndPathTraversal(): void
    {
        $this->assertContains('Content-Type: text/css', $this->get('/assets/style.css'));
        $this->assertTrue(str_starts_with($this->get('/assets/../../config/app.php'), 'HTTP/1.1 404'));
        $this->assertTrue(str_starts_with($this->get('/assets/app.php'), 'HTTP/1.1 404'));
    }

    public function testForeignHostIsRejected(): void
    {
        $this->assertTrue(str_starts_with($this->get('/', 'evil.example:8765'), 'HTTP/1.1 403'), 'защита от DNS-rebinding');
        $this->assertTrue(str_starts_with($this->get('/', 'localhost:8765'), 'HTTP/1.1 200'));
    }

    public function testFormPostWithCsrfAndFlashAcrossRequests(): void
    {
        $raw = $this->post('/sources', ['input' => '@product_jobs', 'kind' => 'auto']);
        $this->assertTrue(str_starts_with($raw, 'HTTP/1.1 303'), strtok($raw, "\r\n"));
        $page = $this->get('/');
        $this->assertContains('Источник добавлен: product_jobs', $page, 'flash пережил пересборку приложения между запросами');
        $this->assertContains('@product_jobs', $page);

        $noToken = $this->server->handleRaw("POST /sources HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 20\r\n\r\ninput=%40other_chan1");
        $this->assertTrue(str_starts_with($noToken, 'HTTP/1.1 303'));
        $this->assertNotContains('@other_chan1', $this->get('/'));
    }

    public function testConnectionsAreSavedToEnvAndAppliedImmediately(): void
    {
        $this->post('/settings/env', ['LETTER_MODE' => 'claude', 'CLAUDE_API_KEY' => 'sk-test-123', 'OPENAI_API_KEY' => '']);
        $env = (string) file_get_contents($this->data . '/.env');
        $this->assertContains('CLAUDE_API_KEY=sk-test-123', $env);
        $this->assertNotContains('OPENAI_API_KEY', $env, 'пустой секрет не затирается');

        $config = App::loadConfig($this->root, $this->data);
        $this->assertSame('claude', $config->get('letters.mode'));
        $this->assertSame('sk-test-123', $config->get('ai.claude.api_key'));

        $page = $this->get('/');
        $this->assertNotContains('sk-test-123', $page, 'секреты не выводятся в интерфейс');
        $this->assertContains('задан', $page);

        $this->post('/settings/env', ['CLAUDE_API_KEY' => '', 'clear' => ['CLAUDE_API_KEY' => '1']]);
        $this->assertSame(null, App::loadConfig($this->root, $this->data)->get('ai.claude.api_key'));
    }

    public function testShutdownStopsServer(): void
    {
        $raw = $this->post('/app/shutdown', []);
        $this->assertContains('остановлен', $raw);
        $this->assertNotContains('X-App-Shutdown', $raw, 'служебный заголовок не уходит в браузер');
    }

    public function testMalformedRequest(): void
    {
        $this->assertTrue(str_starts_with($this->server->handleRaw("garbage\r\n\r\n"), 'HTTP/1.1 400'));
    }

    public function testEnvFileKeepsOtherLinesAndComments(): void
    {
        $file = $this->data . '/x.env';
        file_put_contents($file, "# комментарий\nA=1\nB=2\n");
        EnvFile::update($file, ['B' => 'new value', 'C' => '3']);
        $this->assertSame("# комментарий\nA=1\nB=\"new value\"\nC=3\n", (string) file_get_contents($file));
        $this->assertThrows(\InvalidArgumentException::class, fn () => EnvFile::update($file, ['bad key' => 'x']));
    }

    public function testDataDirResolution(): void
    {
        $this->assertSame($this->data . '/explicit', DataDir::resolve($this->data . '/explicit', null, ['HOME' => '/nonexistent']));
        $this->assertSame($this->data . '/fromenv', DataDir::resolve(null, null, ['TGJP_DATA_DIR' => $this->data . '/fromenv']));
        mkdir($this->data . '/app/TgJobParser-data', 0775, true);
        $this->assertSame($this->data . '/app/TgJobParser-data', DataDir::resolve(null, $this->data . '/app/TgJobParser.exe', ['HOME' => $this->data]), 'портативный режим');

        $this->assertSame('C:\\Users\\v\\AppData\\Local\\TgJobParser', DataDir::osDefault(['LOCALAPPDATA' => 'C:\\Users\\v\\AppData\\Local'], 'Windows'));
        $this->assertSame('/Users/v/Library/Application Support/TgJobParser', DataDir::osDefault(['HOME' => '/Users/v'], 'Darwin'));
        $this->assertSame('/home/v/.local/share/tg-job-parser', DataDir::osDefault(['HOME' => '/home/v'], 'Linux'));
    }
}
