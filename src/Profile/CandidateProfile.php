<?php

declare(strict_types=1);

namespace TgJobParser\Profile;

/** Эффективный профиль кандидата: конфиг + правки пользователя из UI. Неизменяемый. */
final class CandidateProfile
{
    /** @var array<string, TermList> */
    private array $termLists = [];

    /**
     * @param array<string, list<mixed>> $lists
     * @param array<string, bool> $flags
     * @param array<string, int> $thresholds
     * @param array<string, string> $candidate
     * @param list<array<string, mixed>> $experience
     * @param array<string, mixed> $petProject
     */
    public function __construct(
        private readonly array $lists,
        public readonly array $flags,
        public readonly array $thresholds,
        public readonly array $candidate,
        public readonly array $experience,
        public readonly array $petProject,
    ) {
    }

    public function list(string $name): TermList
    {
        return $this->termLists[$name] ??= TermList::from($this->lists[$name] ?? []);
    }

    /** @return list<mixed> */
    public function rawList(string $name): array
    {
        return $this->lists[$name] ?? [];
    }

    public function flag(string $name): bool
    {
        return (bool) ($this->flags[$name] ?? false);
    }

    public function threshold(string $name, int $default): int
    {
        return (int) ($this->thresholds[$name] ?? $default);
    }

    public function contact(string $key): string
    {
        return (string) ($this->candidate[$key] ?? '');
    }

    /** Отпечаток профиля — меняется при любой правке правил. */
    public function fingerprint(): string
    {
        return substr(sha1(serialize([$this->lists, $this->flags, $this->thresholds])), 0, 12);
    }
}
