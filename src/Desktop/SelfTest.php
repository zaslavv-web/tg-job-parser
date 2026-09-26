<?php

declare(strict_types=1);

namespace TgJobParser\Desktop;

use TgJobParser\Filter\Classifier;
use TgJobParser\Kernel\App;
use TgJobParser\Letter\LetterService;
use TgJobParser\Model\Post;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Web\Request;
use TgJobParser\Web\Session;
use TgJobParser\Web\WebKernel;
use Throwable;

/**
 * `--selftest`: проверка собранного файла без сети и без браузера (запускается в CI на каждой ОС).
 * Расширения PHP → БД → конвейер → письмо → HTTP-разбор главной страницы и health.
 */
final class SelfTest
{
    private const REQUIRED_EXTENSIONS = ['pdo_sqlite', 'dom', 'mbstring', 'json', 'curl', 'openssl', 'simplexml', 'libxml'];

    public static function run(string $root): int
    {
        $failed = 0;
        $check = static function (string $name, callable $probe) use (&$failed): void {
            try {
                $detail = $probe();
                fwrite(STDOUT, "OK   {$name}" . ($detail ? " — {$detail}" : '') . PHP_EOL);
            } catch (Throwable $e) {
                $failed++;
                fwrite(STDOUT, "FAIL {$name} — {$e->getMessage()}" . PHP_EOL);
            }
        };

        $check('PHP', static fn () => PHP_VERSION . ' (' . PHP_SAPI . ', ' . PHP_OS_FAMILY . ')');
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $check("ext-{$ext}", static function () use ($ext) {
                if (!extension_loaded($ext)) {
                    throw new \RuntimeException('не загружено');
                }

                return '';
            });
        }

        $data = sys_get_temp_dir() . '/tgjp-selftest-' . bin2hex(random_bytes(4));
        mkdir($data, 0775, true);
        $session = new Session(false);
        $app = null;
        $check('БД и миграции', static function () use ($root, $data, $session, &$app) {
            $app = App::boot($root, [], [Session::class => static fn () => $session], $data);

            return implode(', ', $app->migrate());
        });
        $check('Конвейер фильтров', static function () use (&$app) {
            $c = $app->get(Classifier::class)->classify(
                new RawPost('1', "#вакансия #remote\nHead of Product\nB2B SaaS, AI, удалёнка"),
                new Source(1, 'telegram', 'selftest'),
            );
            if (!$c->isShortlisted()) {
                throw new \RuntimeException('эталонная вакансия не прошла: ' . $c->rejectReason);
            }

            return "скор {$c->score}";
        });
        $check('Шаблонное письмо', static function () use (&$app) {
            $letters = $app->get(LetterService::class);
            $letter = $letters->generate($letters->requestFor(new Post(['id' => 1, 'text' => 'Head of Product, AI', 'language' => 'ru', 'title' => 'Head of Product'])), 'template');

            return mb_strlen($letter->text) . ' символов';
        });
        $check('Claude SDK', static fn () => class_exists(\Anthropic\Client::class) ? 'в комплекте' : throw new \RuntimeException('не упакован'));
        $check('Веб-интерфейс через HTTP', static function () use ($root, $data, $session) {
            $server = new HttpServer(static function (Request $request) use ($root, $data, $session) {
                return (new WebKernel(App::boot($root, [], [Session::class => static fn () => $session], $data)))->handle($request);
            }, $root . '/public');
            foreach (['/' => 'Радар вакансий', '/api/health' => '"ok": true', '/assets/style.css' => ':root'] as $path => $needle) {
                $raw = $server->handleRaw("GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:8765\r\n\r\n");
                if (!str_starts_with($raw, 'HTTP/1.1 200') || !str_contains($raw, $needle)) {
                    throw new \RuntimeException("{$path}: " . strtok($raw, "\r\n"));
                }
            }

            return 'главная, health, статика';
        });

        fwrite(STDOUT, ($failed ? "Самопроверка: ошибок {$failed}" : 'Самопроверка пройдена') . PHP_EOL);

        return $failed ? 1 : 0;
    }
}
