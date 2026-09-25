<?php

declare(strict_types=1);

namespace TgJobParser\Application;

/** Не даёт двум парсингам (cron + кнопка) идти одновременно. Снимается автоматически при падении процесса. */
final class FileLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            return true; // нет прав на lock-файл — не блокируем работу
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
