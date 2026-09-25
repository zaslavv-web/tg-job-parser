<?php

declare(strict_types=1);

namespace TgJobParser\Profile;

/** Элемент списка профиля: метка + шаблоны + произвольные атрибуты (unless, action, flag…). */
final class Term
{
    /**
     * @param list<string> $patterns
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $label,
        public readonly array $patterns,
        public readonly array $meta = [],
    ) {
    }

    public static function from(mixed $definition): self
    {
        if (is_string($definition)) {
            return new self($definition, [$definition]);
        }
        if (!is_array($definition) || !isset($definition['label'])) {
            throw new \InvalidArgumentException('Термин должен быть строкой или массивом с ключом label');
        }
        $label = (string) $definition['label'];
        $patterns = array_values(array_map('strval', (array) ($definition['patterns'] ?? [$label])));
        $meta = $definition;
        unset($meta['label'], $meta['patterns']);

        return new self($label, $patterns, $meta);
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
