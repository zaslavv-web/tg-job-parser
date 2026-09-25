<?php

declare(strict_types=1);

namespace TgJobParser\Filter;

use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Profile\CandidateProfile;
use TgJobParser\Profile\Matcher;
use TgJobParser\Profile\Term;

/**
 * Всё, что шаги конвейера знают о посте. Шаги общаются только через контекст,
 * поэтому их можно переставлять, отключать и добавлять независимо.
 */
final class VacancyContext
{
    public readonly string $normalizedText;

    /** @var array<string, mixed> извлечённые атрибуты: title, company, language, work_format, vacancy_url… */
    private array $attributes = [];

    /** @var list<array{label: string, points?: int}> */
    private array $reasons = [];

    /** @var list<array<string, mixed>> */
    private array $trace = [];

    /** @var array<string, list<Term>> */
    private array $matchCache = [];

    public function __construct(
        public readonly RawPost $post,
        public readonly Source $source,
        public readonly CandidateProfile $profile,
    ) {
        $this->normalizedText = Matcher::normalize($post->text);
    }

    public function text(): string
    {
        return $this->post->text;
    }

    public function title(): string
    {
        return (string) ($this->attributes['title'] ?? '');
    }

    public function normalizedTitle(): string
    {
        return Matcher::normalize($this->title());
    }

    /**
     * Совпадения списка профиля в тексте или заголовке (кешируются).
     *
     * @return list<Term>
     */
    public function matches(string $listName, string $scope = 'text'): array
    {
        $key = $scope . ':' . $listName;
        if (!isset($this->matchCache[$key])) {
            $haystack = $scope === 'title' ? $this->normalizedTitle() : $this->normalizedText;
            $this->matchCache[$key] = Matcher::matchTerms($this->profile->list($listName), $haystack);
        }

        return $this->matchCache[$key];
    }

    public function has(string $listName, string $scope = 'text'): bool
    {
        return $this->matches($listName, $scope) !== [];
    }

    /** @param list<string> $patterns */
    public function matchesPatterns(array $patterns, string $scope = 'text'): ?string
    {
        $haystack = $scope === 'title' ? $this->normalizedTitle() : $this->normalizedText;

        return Matcher::matchesAny($patterns, $haystack);
    }

    public function isRemote(): bool
    {
        return $this->has('remote_markers');
    }

    public function set(string $attribute, mixed $value): void
    {
        $this->attributes[$attribute] = $value;
        if ($attribute === 'title') {
            foreach (array_keys($this->matchCache) as $key) {
                if (str_starts_with($key, 'title:')) {
                    unset($this->matchCache[$key]);
                }
            }
        }
    }

    public function get(string $attribute, mixed $default = null): mixed
    {
        return $this->attributes[$attribute] ?? $default;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function addReason(string $label, ?int $points = null): void
    {
        $reason = ['label' => $label];
        if ($points !== null) {
            $reason['points'] = $points;
        }
        $this->reasons[] = $reason;
    }

    /** @return list<array{label: string, points?: int}> */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /** @param array<string, mixed> $entry */
    public function trace(array $entry): void
    {
        $this->trace[] = $entry;
    }

    /** @return list<array<string, mixed>> */
    public function traceLog(): array
    {
        return $this->trace;
    }
}
