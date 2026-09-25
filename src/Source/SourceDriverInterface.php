<?php

declare(strict_types=1);

namespace TgJobParser\Source;

use TgJobParser\Model\FetchResult;
use TgJobParser\Model\Source;

/**
 * Драйвер источника вакансий. Новый источник (HH API, LinkedIn, e-mail-рассылка…) —
 * это новый класс с этим контрактом + строка в config/modules.php. Ядро не меняется.
 */
interface SourceDriverInterface
{
    /** Человекочитаемое название для UI. */
    public function label(): string;

    /** Подсказка формата ввода для UI. */
    public function inputHint(): string;

    /** Может ли драйвер принять такой ввод пользователя (для автоопределения типа). */
    public function supports(string $input): bool;

    /**
     * Нормализует ввод в описание источника.
     *
     * @param array<string, mixed> $options доп. настройки из формы
     * @throws \InvalidArgumentException если ввод некорректен
     */
    public function describe(string $input, array $options = []): Source;

    /**
     * Новые посты после курсора $source->lastPostId (инкрементальный парсинг).
     *
     * @throws \Throwable любая ошибка изолируется ParseService-ом на уровне источника
     */
    public function fetch(Source $source, FetchOptions $options): FetchResult;

    /** Готов ли драйвер к работе (зависимости, ключи) — null, если да, иначе причина. */
    public function unavailableReason(): ?string;
}
