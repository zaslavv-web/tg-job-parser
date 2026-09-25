<?php

declare(strict_types=1);

namespace TgJobParser\Filter;

/** Решение шага: пропустить дальше, отклонить вакансию или признать пост не-вакансией. */
final class StepResult
{
    public const CONTINUE = 'continue';
    public const REJECT = 'reject';
    public const NOT_VACANCY = 'not_vacancy';
    public const ACCEPT = 'accept';

    private function __construct(
        public readonly string $decision,
        public readonly ?string $reason = null,
        public readonly ?string $status = null,
        public readonly ?int $score = null,
    ) {
    }

    public static function pass(?string $note = null): self
    {
        return new self(self::CONTINUE, $note);
    }

    public static function reject(string $reason, ?int $score = null): self
    {
        return new self(self::REJECT, $reason, null, $score);
    }

    public static function notVacancy(string $reason): self
    {
        return new self(self::NOT_VACANCY, $reason);
    }

    /** Терминальное решение: вакансия принята с итоговым статусом. */
    public static function accept(string $status, int $score, ?string $note = null): self
    {
        return new self(self::ACCEPT, $note, $status, $score);
    }

    public function isTerminal(): bool
    {
        return $this->decision !== self::CONTINUE;
    }
}
