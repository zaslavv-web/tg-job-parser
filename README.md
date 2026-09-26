# Радар вакансий (tg-job-parser)

Находит вакансии в Telegram-каналах и на сайтах, отбирает подходящие по профилю
и пишет к ним сопроводительные письма.

## Установка — один файл, запуск с рабочего стола

1. Скачайте файл для своей системы в разделе **Releases** (или во вкладке Actions → последняя сборка → `executables`):
   - Windows — `TgJobParser.exe`
   - macOS (M1–M4) — `TgJobParser-macos-arm64.zip`, Intel — `TgJobParser-macos-x64.zip`
   - Linux — `TgJobParser-linux-x64.zip`
2. Положите файл на рабочий стол и запустите двойным кликом.
   Откроется окно консоли и браузер с интерфейсом. Окно можно свернуть; закрыли окно — программа выключена.
3. Повторный двойной клик просто откроет интерфейс в браузере, второй копии не будет.

Ничего устанавливать не нужно: PHP и все библиотеки внутри файла.

**Первый запуск на macOS:** система не знает разработчика, поэтому один раз откройте файл
через правый клик → «Открыть» → «Открыть» (или `xattr -d com.apple.quarantine TgJobParser-macos-arm64`).
**Windows:** SmartScreen может показать «Windows защитила компьютер» → «Подробнее» → «Выполнить в любом случае».

Где хранятся данные (база, логи, ключи):
- Windows — `%LOCALAPPDATA%\TgJobParser`
- macOS — `~/Library/Application Support/TgJobParser`
- Linux — `~/.local/share/tg-job-parser`

Портативный режим: создайте рядом с программой папку `TgJobParser-data` — данные будут храниться в ней
(удобно для флешки). Удалить программу = удалить файл и папку данных.

Ключи Claude/OpenAI, бот уведомлений и Telethon настраиваются в интерфейсе, раздел «Подключения».
Автопарсинг работает, пока программа запущена (интервал — в «Параметрах поиска»).

## Разработка
```bash
composer install
php app.php                      # тот же десктоп-режим из исходников (данные в каталоге ОС)
php app.php --data=var           # данные в ./var
php app.php parse                # CLI-команды: parse, cron, rescore, sources, seed, letter, health
php tests/run.php                # тесты
php -d phar.readonly=0 build/build-phar.php && php dist/tg-job-parser.phar --selftest
```
Архитектура, модули и как вносить изменения — [`rtfm_tg-job-parser.md`](./rtfm_tg-job-parser.md).
