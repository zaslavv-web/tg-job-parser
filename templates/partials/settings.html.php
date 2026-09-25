<?php
/** @var callable $e */
/** @var \TgJobParser\Profile\CandidateProfile $profile */
$flagLabels = ['require_target_role' => 'Отклонять, если должность не из белого списка', 'allow_gambling' => 'Разрешить iGaming / гемблинг'];
$hints = [
    'letter_requirements' => 'Обычный текст — инструкция для AI. Строка, начинающаяся с «+», вставляется в шаблонное письмо дословно.',
    'vacancy_markers' => 'Поддерживается «*» (удален* = удаленка, удаленно) и /regex/.',
];
?>
<details class="panel" id="settings">
    <summary>Параметры поиска и писем</summary>
    <form method="post" action="/settings/general" class="general">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
        <?php foreach ($flagsConfig as $flag): ?>
            <input type="hidden" name="flags_present[]" value="<?= $e($flag) ?>">
            <label><input type="checkbox" name="flags[<?= $e($flag) ?>]" <?= $profile->flag($flag) ? 'checked' : '' ?>> <?= $e($flagLabels[$flag] ?? $flag) ?></label>
        <?php endforeach ?>
        <label>Порог отклонения <input type="number" name="min_score" min="0" max="100" value="<?= $profile->threshold('min_score', 40) ?>"></label>
        <label>Порог «рекомендуется» <input type="number" name="recommended" min="0" max="100" value="<?= $profile->threshold('recommended', 70) ?>"></label>
        <label><input type="checkbox" name="cron_enabled" <?= $cron['enabled'] ? 'checked' : '' ?>> Автопарсинг каждые</label>
        <label><input type="number" name="cron_interval" min="5" max="1440" value="<?= (int) $cron['interval'] ?>"> мин</label>
        <button class="btn btn--primary">Сохранить</button>
    </form>
    <div class="lists">
        <?php foreach ($lists as $name => $list): ?>
            <section class="list">
                <h3><?= $e($list['label']) ?></h3>
                <?php if (isset($hints[$name])): ?><p class="muted small"><?= $e($hints[$name]) ?></p><?php endif ?>
                <ul class="chips">
                    <?php foreach ($list['items'] as $item): ?>
                        <li class="chip <?= $item['hidden'] ? 'chip--hidden' : '' ?> chip--<?= $e($item['origin']) ?>">
                            <?= $e($item['label']) ?>
                            <form method="post" action="/settings/list/<?= $item['hidden'] ? 'restore' : 'remove' ?>" class="inline">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="list" value="<?= $e($name) ?>">
                                <input type="hidden" name="value" value="<?= $e($item['label']) ?>">
                                <button class="chip__btn" title="<?= $item['hidden'] ? 'Вернуть' : 'Убрать' ?>"><?= $item['hidden'] ? '↺' : '×' ?></button>
                            </form>
                        </li>
                    <?php endforeach ?>
                </ul>
                <form method="post" action="/settings/list/add" class="add-item">
                    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="list" value="<?= $e($name) ?>">
                    <input type="text" name="value" placeholder="Добавить…" required maxlength="300">
                    <button class="btn btn--small">+</button>
                </form>
            </section>
        <?php endforeach ?>
    </div>
    <form method="post" action="/rescore" class="inline">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
        <button class="btn">Пересчитать все посты по текущим правилам</button>
    </form>
</details>
