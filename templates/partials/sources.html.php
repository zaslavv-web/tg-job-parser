<?php /** @var callable $e */ ?>
<section class="panel" id="sources">
    <h2>Каналы и источники</h2>
    <form method="post" action="/sources" class="add-source">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
        <input type="text" name="input" placeholder="@username, t.me/…, RSS или сайт с вакансиями" required>
        <select name="kind" title="Тип источника">
            <option value="auto">Тип: авто</option>
            <?php foreach ($drivers as $kind => $driver): ?>
                <option value="<?= $e($kind) ?>" <?= $driver->unavailableReason() ? 'title="' . $e($driver->unavailableReason()) . '"' : '' ?>><?= $e($driver->label()) ?></option>
            <?php endforeach ?>
        </select>
        <button class="btn btn--primary">Добавить</button>
        <details class="advanced">
            <summary>Для сайтов (необязательно)</summary>
            <label><input type="checkbox" name="assume_vacancy" checked> На странице только вакансии (не искать маркеры)</label>
            <label><input type="checkbox" name="fetch_details" checked> Открывать страницы вакансий за описанием</label>
            <input type="text" name="item_xpath" placeholder="XPath карточки, напр. //div[@class='vacancy']">
            <input type="text" name="link_pattern" placeholder="Regex ссылок на вакансии, напр. ~/jobs/\d+~">
        </details>
    </form>
    <?php if ($sources): ?>
    <div class="table-scroll"><table class="table">
        <thead><tr><th>Источник</th><th>Тип</th><th>Статус</th><th>Последний парсинг</th><th></th></tr></thead>
        <?php foreach ($sources as $s): ?>
            <tr>
                <td><a href="<?= $e($s->url ?? '#') ?>" target="_blank" rel="noopener"><?= $e($s->kind === 'telegram' ? '@' . $s->handle : $s->displayName()) ?></a>
                    <?php if ($s->title && $s->kind === 'telegram'): ?><div class="muted small"><?= $e($s->title) ?></div><?php endif ?></td>
                <td><?= $e(($drivers[$s->kind] ?? null)?->label() ?? $s->kind) ?></td>
                <td>
                    <?php $labels = ['active' => ['ok', 'активен'], 'error' => ['err', 'ошибка'], 'disabled' => ['off', 'отключен']]; [$cls, $label] = $labels[$s->status] ?? ['off', $s->status]; ?>
                    <span class="badge badge--<?= $cls ?>" <?= $s->lastError ? 'title="' . $e($s->lastError) . '"' : '' ?>><?= $label ?></span>
                    <?php if ($s->lastError): ?><div class="muted small err-text"><?= $e(mb_substr($s->lastError, 0, 140)) ?></div><?php endif ?>
                </td>
                <td class="nowrap"><?= $e($s->lastParsedAt ? date('d.m H:i', strtotime($s->lastParsedAt)) : '—') ?><?= $s->lastPostId && in_array($s->kind, ['telegram', 'telethon'], true) ? '<div class="muted small">пост #' . $e($s->lastPostId) . '</div>' : '' ?></td>
                <td class="nowrap actions">
                    <form method="post" action="/parse" class="inline"><input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="source" value="<?= (int) $s->id ?>"><button class="btn btn--small" title="Проверить только этот источник">↻</button></form>
                    <form method="post" action="/sources/<?= (int) $s->id ?>/toggle" class="inline"><input type="hidden" name="_token" value="<?= $e($csrf) ?>"><button class="btn btn--small"><?= $s->isActive ? 'Выключить' : 'Включить' ?></button></form>
                    <form method="post" action="/sources/<?= (int) $s->id ?>/delete" class="inline" data-confirm="Удалить источник и все его посты?"><input type="hidden" name="_token" value="<?= $e($csrf) ?>"><button class="btn btn--small btn--danger">Удалить</button></form>
                </td>
            </tr>
        <?php endforeach ?>
    </table></div>
    <?php endif ?>
</section>
