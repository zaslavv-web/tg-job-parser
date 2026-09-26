<?php

declare(strict_types=1);

namespace TgJobParser\Desktop;

use TgJobParser\Kernel\App;
use TgJobParser\Web\Request;
use TgJobParser\Web\Response;
use TgJobParser\Web\Session;
use TgJobParser\Web\WebKernel;
use Throwable;

/**
 * Десктоп-режим: двойной клик → локальный сервер → браузер.
 *  - повторный запуск не поднимает второй сервер, а открывает вкладку в уже работающем;
 *  - приложение пересобирается на каждый запрос (правки .env и конфигурации применяются сразу,
 *    состояние одного запроса не протекает в другой); общая только сессия (CSRF, сообщения);
 *  - автопарсинг — отдельным дочерним процессом раз в минуту (команда cron сама решает, пора ли),
 *    поэтому долгий парсинг не блокирует интерфейс, а его падение не роняет сервер.
 */
final class Launcher
{
    private const PORTS = [8765, 8766, 8767, 8768, 0];

    /** @var resource|null */
    private $cronProcess = null;
    private int $lastCronSpawn = 0;

    public function __construct(
        private readonly string $root,
        private readonly string $dataDir,
        private readonly string $entryScript,
    ) {
    }

    public function run(bool $openBrowser = true): int
    {
        self::utf8Console();
        $stateFile = $this->dataDir . '/run/server.json';

        $existing = $this->runningInstance($stateFile);
        if ($existing !== null) {
            self::say("Радар вакансий уже запущен: {$existing}");
            if ($openBrowser) {
                Browser::open($existing);
            }

            return 0;
        }

        $session = new Session(false);
        $token = bin2hex(random_bytes(8));
        $server = new HttpServer(function (Request $request) use ($session, $token): Response {
            if ($request->path === '/__ping') {
                return new Response($token, 200, ['Content-Type' => 'text/plain']);
            }

            return $this->handle($request, $session);
        }, $this->root . '/public');

        $port = $server->listen(self::PORTS);
        $url = "http://127.0.0.1:{$port}/";
        if (!is_dir(dirname($stateFile))) {
            mkdir(dirname($stateFile), 0775, true);
        }
        file_put_contents($stateFile, json_encode(['url' => $url, 'token' => $token, 'pid' => getmypid()]));

        // Схема БД — до первого запроса, чтобы ошибка была видна сразу в окне
        App::boot($this->root, [], [], $this->dataDir)->migrate();

        self::say('Радар вакансий запущен: ' . $url);
        self::say('Данные: ' . $this->dataDir);
        self::say('Окно можно свернуть. Закрытие окна (или кнопка «Выключить» в интерфейсе) останавливает программу.');
        if ($openBrowser) {
            Browser::open($url);
        }

        $server->serve(fn () => $this->tick());
        @unlink($stateFile);
        self::say('Остановлено.');

        return 0;
    }

    private function handle(Request $request, Session $session): Response
    {
        try {
            $app = App::boot($this->root, [], [Session::class => static fn () => $session], $this->dataDir);

            return (new WebKernel($app))->handle($request);
        } catch (Throwable $e) {
            return new Response('<h1>Ошибка запуска</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>', 500);
        }
    }

    /** Раз в минуту — фоновый `cron`, если предыдущий уже завершился. */
    private function tick(): void
    {
        if (is_resource($this->cronProcess)) {
            if (proc_get_status($this->cronProcess)['running']) {
                return;
            }
            proc_close($this->cronProcess);
            $this->cronProcess = null;
        }
        if (time() - $this->lastCronSpawn < 60) {
            return;
        }
        $this->lastCronSpawn = time();
        $log = $this->dataDir . '/log/cron.log';
        if (!is_dir(dirname($log))) {
            mkdir(dirname($log), 0775, true);
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = SelfCommand::build(['cron', '--data=' . $this->dataDir], $this->entryScript);
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        $this->cronProcess = is_resource($process) ? $process : null;
    }

    /** URL уже работающего экземпляра (проверка по секретному токену), иначе null. */
    private function runningInstance(string $stateFile): ?string
    {
        if (!is_file($stateFile)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($stateFile), true);
        if (!is_array($state) || empty($state['url']) || empty($state['token'])) {
            return null;
        }
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true, 'proxy' => '']]);
        $answer = @file_get_contents(rtrim((string) $state['url'], '/') . '/__ping', false, $context);

        return $answer === $state['token'] ? (string) $state['url'] : null;
    }

    private static function utf8Console(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        }
    }

    public static function say(string $line): void
    {
        fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $line . PHP_EOL);
    }
}
