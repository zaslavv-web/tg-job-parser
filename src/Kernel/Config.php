<?php

declare(strict_types=1);

namespace TgJobParser\Kernel;

/** Неизменяемый доступ к конфигурации по точечным ключам: get('parsing.max_pages'). */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /** @return array<mixed> */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    /** Копия с переопределёнными значениями — удобно в тестах. */
    public function with(string $key, mixed $value): self
    {
        $items = $this->items;
        $node = &$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
        unset($node);

        return new self($items);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
