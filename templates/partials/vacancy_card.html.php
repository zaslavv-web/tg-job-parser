<?php
/** @var callable $e */
/** @var \TgJobParser\Model\Post $post */
$status = $post->status();
$reasons = $post->reasons();
$positive = array_filter($reasons, static fn (array $r): bool => ($r['points'] ?? 0) > 0);
$negative = array_filter($reasons, static fn (array $r): bool => ($r['points'] ?? 0) < 0);
$sourceLabel = in_array($post->get('source_kind'), ['telegram', 'telethon'], true) ? '@' . $post->get('source_handle') : ($post->get('source_title') ?: $post->get('source_handle'));
$vacancyUrl = $post->get('vacancy_url') ?: $post->get('url');
?>
<article class="card card--<?= $e($status) ?>" id="vacancy-<?= $post->id() ?>">
    <header class="card__head">
        <span class="score">[Скоринг: <?= (int) $post->get('score') ?>/100]</span>
        <span class="status"><?= $e($statusLabels[$status] ?? $status) ?></span>
        <?php if ($post->get('manual_status')): ?><span class="badge badge--off">вручную</span><?php endif ?>
    </header>
    <dl class="card__facts">
        <div><dt>📋 Вакансия</dt><dd><?= $e($post->get('title') ?: '—') ?></dd></div>
        <div><dt>🏢 Компания</dt><dd><?= $e($post->get('company') ?: 'не удалось извлечь') ?></dd></div>
        <div><dt>📍 Формат</dt><dd><?= $e($post->get('work_format') ?: 'не указан') ?></dd></div>
        <div><dt>🔗 Ссылка</dt><dd><a href="<?= $e($vacancyUrl) ?>" target="_blank" rel="noopener"><?= $e(mb_strimwidth((string) $vacancyUrl, 0, 70, '…')) ?></a></dd></div>
        <div><dt>📅 Опубликовано</dt><dd><?= $e($post->get('published_at') ? date('Y-m-d H:i', strtotime((string) $post->get('published_at'))) : '—') ?></dd></div>
        <div><dt>📡 Канал</dt><dd><a href="<?= $e($post->get('url')) ?>" target="_blank" rel="noopener"><?= $e($sourceLabel) ?></a></dd></div>
    </dl>
    <div class="card__why">
        <b>💼 Почему подходит:</b>
        <ul>
            <?php foreach ($positive as $r): ?><li>✅ <?= $e($r['label']) ?> <span class="muted">+<?= (int) $r['points'] ?></span></li><?php endforeach ?>
            <?php foreach ($negative as $r): ?><li>⚠️ <?= $e($r['label']) ?> <span class="muted"><?= (int) $r['points'] ?></span></li><?php endforeach ?>
        </ul>
    </div>
    <details class="card__post"><summary>Текст поста</summary><pre><?= $e($post->text()) ?></pre></details>
    <div class="card__letter">
        <b>📝 Сопроводительное письмо</b>
        <?php if ($letter): ?>
            <span class="muted small">(<?= $e($letter['mode']) ?>, <?= $e($letter['language']) ?><?= $letter['note'] ? ' — ' . $e($letter['note']) : '' ?>)</span>
            <textarea class="letter" id="letter-<?= $post->id() ?>" rows="10"><?= $e($letter['text']) ?></textarea>
        <?php else: ?>
            <p class="muted">Письмо ещё не сгенерировано.</p>
        <?php endif ?>
    </div>
    <footer class="card__actions">
        <?php if ($letter): ?><button class="btn btn--primary" data-copy="letter-<?= $post->id() ?>">Копировать письмо</button><?php endif ?>
        <a class="btn" href="<?= $e($vacancyUrl) ?>" target="_blank" rel="noopener">Открыть вакансию</a>
        <form method="post" action="/vacancies/<?= $post->id() ?>/reject" class="inline">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <button class="btn btn--danger">Отклонить вручную</button>
        </form>
        <form method="post" action="/vacancies/<?= $post->id() ?>/letter" class="inline regen" data-busy="Генерирую…">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <select name="mode">
                <?php foreach ($letterModes as $mode => $reason): ?>
                    <option value="<?= $e($mode) ?>" <?= $reason ? 'disabled title="' . $e($reason) . '"' : '' ?> <?= $mode === $defaultMode ? 'selected' : '' ?>><?= $e($mode === 'template' ? 'шаблон' : $mode) ?></option>
                <?php endforeach ?>
            </select>
            <select name="lang">
                <option value="">язык вакансии</option>
                <option value="ru">русский</option>
                <option value="en">английский</option>
            </select>
            <button class="btn"><?= $letter ? 'Перегенерировать' : 'Сгенерировать' ?></button>
        </form>
        <?php if ($post->get('manual_status')): ?>
            <form method="post" action="/vacancies/<?= $post->id() ?>/reset" class="inline">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <button class="btn btn--small">Снять ручное решение</button>
            </form>
        <?php endif ?>
    </footer>
</article>
