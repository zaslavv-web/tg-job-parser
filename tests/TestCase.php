<?php

declare(strict_types=1);

namespace TgJobParser\Tests;

use RuntimeException;

abstract class TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function assertTrue(bool $value, string $message = 'Ожидалось true'): void
    {
        if (!$value) {
            throw new RuntimeException($message);
        }
    }

    protected function assertFalse(bool $value, string $message = 'Ожидалось false'): void
    {
        $this->assertTrue(!$value, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(trim($message . ' Ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true)));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(trim($message . " Строка не содержит «{$needle}»: " . mb_substr($haystack, 0, 400)));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new RuntimeException(trim($message . " Строка не должна содержать «{$needle}»"));
        }
    }

    protected function assertGreaterOrEqual(int|float $min, int|float $actual, string $message = ''): void
    {
        if ($actual < $min) {
            throw new RuntimeException(trim("{$message} Ожидалось >= {$min}, получено {$actual}"));
        }
    }

    protected function assertLessOrEqual(int|float $max, int|float $actual, string $message = ''): void
    {
        if ($actual > $max) {
            throw new RuntimeException(trim("{$message} Ожидалось <= {$max}, получено {$actual}"));
        }
    }

    /** @param callable(): mixed $fn */
    protected function assertThrows(string $class, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new RuntimeException('Ожидалось ' . $class . ', брошено ' . $e::class . ': ' . $e->getMessage());
        }
        throw new RuntimeException('Ожидалось исключение ' . $class);
    }

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }
}
