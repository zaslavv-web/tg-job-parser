<?php

declare(strict_types=1);

namespace TgJobParser\Letter\Generator;

use TgJobParser\Letter\LetterRequest;

/**
 * Генератор сопроводительного письма. Любой генератор может упасть или быть недоступен —
 * LetterService тогда переходит к следующему, последним всегда идёт шаблонный.
 */
interface LetterGeneratorInterface
{
    /** Причина недоступности (нет ключа, SDK…) или null, если готов. */
    public function unavailableReason(): ?string;

    /** Сгенерированный текст письма. @throws \Throwable */
    public function generate(LetterRequest $request): string;

    /** 'template' или 'ai' — от этого зависят лимиты проверки. */
    public function kind(): string;
}
