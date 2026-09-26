<?php
/** @var callable $e */
/** @var \TgJobParser\Web\View $view */
$statusLabels = ['recommended' => '✅ Рекомендуется', 'maybe' => '🤔 Возможно интересно'];
$qs = static fn (array $extra = []): string => '?' . http_build_query(array_merge(['show' => $filters['show'], 'period' => $filters['period'], 'min_score' => $filters['min_score']], $extra));
?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Радар вакансий</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="wrap topbar__inner">
        <h1>Радар вакансий</h1>
        <div class="topbar__meta">
            <?php if ($lastRun): ?>
                Последний парсинг: <?= $e(date('d.m H:i', strtotime((string) $lastRun['finished_at']))) ?>
                (<?= $e($lastRun['trigger_name']) ?>, новых постов <?= (int) $lastRun['posts_new'] ?>)
            <?php else: ?>Парсинг ещё не запускался<?php endif ?>
        </div>
        <form method="post" action="/parse" class="inline" data-busy="Проверяю каналы…">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <button class="btn btn--primary">🔄 Проверить все каналы</button>
        </form>
    </div>
</header>

<main class="wrap">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash--<?= $e($flash['type']) ?>"><?= $e($flash['message']) ?></div>
    <?php endforeach ?>

    <!-- Секция 1: каналы и источники -->
    <?= $view->render('partials/sources', compact('sources', 'drivers', 'csrf', 'e')) ?>

    <!-- Секция 2: панель фильтров -->
    <details class="panel" id="filters" open>
        <summary>Фильтры</summary>
        <form method="get" action="/#results" class="filters">
            <fieldset>
                <label><input type="checkbox" name="show[]" value="recommended" <?= in_array('recommended', $filters['show'], true) ? 'checked' : '' ?>> Рекомендуется</label>
                <label><input type="checkbox" name="show[]" value="maybe" <?= in_array('maybe', $filters['show'], true) ? 'checked' : '' ?>> Возможно интересно</label>
            </fieldset>
            <fieldset>
                <?php foreach (['today' => 'Сегодня', 'week' => 'Неделя', 'all' => 'Все'] as $value => $label): ?>
                    <label><input type="radio" name="period" value="<?= $value ?>" <?= $filters['period'] === $value ? 'checked' : '' ?>> <?= $label ?></label>
                <?php endforeach ?>
            </fieldset>
            <fieldset class="slider">
                <label for="min_score">Мин. скор: <output id="min_score_out"><?= (int) $filters['min_score'] ?></output></label>
                <input type="range" id="min_score" name="min_score" min="0" max="100" step="5" value="<?= (int) $filters['min_score'] ?>" data-output="min_score_out">
            </fieldset>
            <button class="btn">Применить</button>
        </form>
    </details>

    <!-- Секция 3: результаты -->
    <section class="panel" id="results">
        <div class="results__head">
            <h2>Вакансии</h2>
            <div class="counter">Найдено: <b><?= (int) $found ?></b> подходящих, <b><?= (int) $rejectedCount ?></b> отклонено<?= $notVacancyCount ? ', ' . (int) $notVacancyCount . ' постов — не вакансии' : '' ?></div>
        </div>
        <?php if (!$items): ?>
            <p class="muted">Пока пусто. Добавьте каналы и нажмите «Проверить все каналы» — или ослабьте фильтры.</p>
        <?php endif ?>
        <?php foreach ($items as $item): ?>
            <?= $view->render('partials/vacancy_card', ['post' => $item['post'], 'letter' => $item['letter'], 'csrf' => $csrf, 'e' => $e, 'statusLabels' => $statusLabels, 'letterModes' => $letterModes, 'defaultMode' => $defaultMode]) ?>
        <?php endforeach ?>
    </section>

    <!-- Секция 4: лог отклонённых -->
    <details class="panel" id="rejected">
        <summary>Лог отклонённых (<?= count($rejected) ?>)</summary>
        <?php if (!$rejected): ?><p class="muted">Отклонённых нет.</p><?php endif ?>
        <div class="table-scroll"><table class="table">
            <?php foreach ($rejected as $post): ?>
                <tr>
                    <td class="nowrap"><?= $e($post->get('published_at') ? date('d.m H:i', strtotime((string) $post->get('published_at'))) : '') ?></td>
                    <td><a href="<?= $e($post->get('url')) ?>" target="_blank" rel="noopener"><?= $e($post->get('title') ?: mb_substr($post->text(), 0, 80)) ?></a>
                        <div class="muted small"><?= $e(in_array($post->get('source_kind'), ['telegram', 'telethon'], true) ? '@' . $post->get('source_handle') : ($post->get('source_title') ?: $post->get('source_handle'))) ?><?= $post->get('score') !== null ? ' · скор ' . (int) $post->get('score') : '' ?></div></td>
                    <td><?= $e($post->get('manual_status') === 'rejected' ? 'Отклонено вручную' : $post->get('reject_reason')) ?></td>
                    <td class="nowrap">
                        <form method="post" action="/vacancies/<?= $post->id() ?>/restore" class="inline">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <button class="btn btn--small">Пересмотреть</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
        </table></div>
    </details>

    <!-- Секции 5: параметры поиска и писем -->
    <?= $view->render('partials/settings', compact('lists', 'csrf', 'e', 'profile', 'flagsConfig', 'cron')) ?>

    <!-- Подключения: ключи AI, бот уведомлений, Telethon -->
    <details class="panel" id="connections">
        <summary>Подключения (AI, уведомления, Telethon)</summary>
        <form method="post" action="/settings/env" class="connections">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <?php foreach ($connections as $c): ?>
                <label><span><?= $e($c['label']) ?></span>
                    <input type="<?= $c['secret'] ? 'password' : 'text' ?>" name="<?= $e($c['key']) ?>" value="<?= $e($c['value']) ?>" autocomplete="off"
                           placeholder="<?= $c['secret'] ? ($c['set'] ? '•••••• задан (оставьте пустым, чтобы не менять)' : 'не задан') : '' ?>">
                    <?php if ($c['secret'] && $c['set']): ?><small><input type="checkbox" name="clear[<?= $e($c['key']) ?>]" value="1"> удалить</small><?php endif ?>
                </label>
            <?php endforeach ?>
            <button class="btn btn--primary">Сохранить подключения</button>
            <p class="muted small">Хранится в <code><?= $e($dataDir) ?>/.env</code> на этом компьютере.</p>
        </form>
    </details>
</main>
<footer class="wrap muted small">Модули писем:
    <?php foreach ($letterModes as $mode => $reason): ?>
        <span title="<?= $e($reason ?? 'готов') ?>"><?= $reason === null ? '●' : '○' ?> <?= $e($mode) ?></span>
    <?php endforeach ?>
    · <a href="/api/health">health</a> · <a href="/api/vacancies">API</a>
    · данные: <code><?= $e($dataDir) ?></code>
    <form method="post" action="/app/shutdown" class="inline" data-confirm="Выключить программу? Автопарсинг остановится до следующего запуска.">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
        <button class="btn btn--small">⏻ Выключить программу</button>
    </form>
</footer>
<script src="/assets/app.js"></script>
</body>
</html>
