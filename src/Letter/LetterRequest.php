<?php

declare(strict_types=1);

namespace TgJobParser\Letter;

use TgJobParser\Profile\CandidateProfile;

/** Всё, что нужно генератору письма. Генераторы не ходят в БД. */
final class LetterRequest
{
    /**
     * @param list<array<string, mixed>> $cases отобранные кейсы из профиля (ТЗ 2.7)
     * @param list<string> $domains распознанные приоритетные домены
     * @param list<string> $requirements требования пользователя к письму (раздел 5)
     */
    public function __construct(
        public readonly string $vacancyText,
        public readonly string $language,
        public readonly ?string $role,
        public readonly ?string $company,
        public readonly array $cases,
        public readonly bool $mentionPetProject,
        public readonly array $domains,
        public readonly array $requirements,
        public readonly CandidateProfile $profile,
    ) {
    }
}
