<?php

declare(strict_types=1);

/*
 * Проверка драйвера LinkedIn на живой выдаче (запускается в CI отдельным workflow, не в unit-тестах):
 *   php tests/live/linkedin_live.php
 * Ловит изменение разметки LinkedIn раньше, чем это заметит пользователь.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use TgJobParser\Application\SeedService;
use TgJobParser\Http\CurlHttpClient;
use TgJobParser\Kernel\App;
use TgJobParser\Source\Driver\LinkedInDriver;
use TgJobParser\Source\FetchOptions;

$root = dirname(__DIR__, 2);
$config = App::loadConfig($root, sys_get_temp_dir() . '/tgjp-live')->with('linkedin.max_pages', 2)->with('linkedin.request_delay_ms', 2000);
$driver = new LinkedInDriver(new CurlHttpClient(timeout: 30), $config);

$search = $config->array('profile.linkedin_searches')[0];
$source = $driver->describe(SeedService::linkedInUrl($search));
echo "Поиск: {$source->title}\n{$source->url}\n\n";

$result = $driver->fetch($source, new FetchOptions(maxDetailPages: 3));
printf("Вакансий в выдаче (2 страницы): %d\n", count($result->posts));
foreach (array_slice($result->posts, 0, 3) as $post) {
    echo "---\n" . mb_substr($post->text, 0, 400) . "\n[{$post->url}]\n";
}
$withDescription = array_filter($result->posts, static fn ($p): bool => mb_strlen($p->text) > 400);
printf("\nС описанием: %d\n", count($withDescription));

if ($result->posts === []) {
    fwrite(STDERR, "::error::LinkedIn не вернул ни одной вакансии — разметка изменилась или запросы блокируются\n");
    exit(1);
}
if ($withDescription === []) {
    fwrite(STDERR, "::warning::Карточки есть, но описания вакансий не разобрались\n");
}
