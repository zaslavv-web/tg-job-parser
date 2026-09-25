<?php

declare(strict_types=1);

namespace TgJobParser\Profile;

/**
 * Сопоставление текста с шаблонами профиля.
 *   'head of product' — фраза целиком, по границам слов, без учёта регистра, ё = е
 *   'удален*'         — * = любое продолжение слова
 *   '/regex/flags'    — регулярное выражение как есть
 * Невалидный пользовательский regex не роняет сервис — просто не совпадает.
 */
final class Matcher
{
    /** @var array<string, string|false> */
    private static array $compiled = [];

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['ё' => 'е', '’' => "'", '‘' => "'", '–' => '-', '—' => '-', "\u{00A0}" => ' ']);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /** $normalizedText — результат normalize(). */
    public static function matchesPattern(string $pattern, string $normalizedText): bool
    {
        $regex = self::compile($pattern);

        return $regex !== false && @preg_match($regex, $normalizedText) === 1;
    }

    /** @param list<string> $patterns */
    public static function matchesAny(array $patterns, string $normalizedText): ?string
    {
        foreach ($patterns as $pattern) {
            if (self::matchesPattern($pattern, $normalizedText)) {
                return $pattern;
            }
        }

        return null;
    }

    /** @return list<Term> совпавшие термины в порядке списка */
    public static function matchTerms(TermList $list, string $normalizedText): array
    {
        $matched = [];
        foreach ($list as $term) {
            if (self::matchesAny($term->patterns, $normalizedText) !== null) {
                $matched[] = $term;
            }
        }

        return $matched;
    }

    public static function isValidPattern(string $pattern): bool
    {
        return self::compile($pattern) !== false;
    }

    private static function compile(string $pattern): string|false
    {
        if (array_key_exists($pattern, self::$compiled)) {
            return self::$compiled[$pattern];
        }
        if (strlen($pattern) > 2 && $pattern[0] === '/' && preg_match('~^/.+/[a-zA-Z]*$~s', $pattern)) {
            $regex = $pattern;
            if (!str_contains(substr($pattern, strrpos($pattern, '/') + 1), 'u')) {
                $regex .= 'u';
            }
        } else {
            $normalized = self::normalize(trim($pattern));
            if ($normalized === '') {
                return self::$compiled[$pattern] = false;
            }
            $parts = array_map(static fn (string $p): string => preg_quote($p, '~'), explode('*', $normalized));
            $body = implode('[\p{L}\p{N}]*', $parts);
            // Граница слова для кириллицы/латиницы/цифр; спецсимволы (#, &, +) внутри шаблона допустимы.
            $regex = '~(?<![\p{L}\p{N}_])' . $body . '(?![\p{L}\p{N}_])~u';
        }
        $ok = @preg_match($regex, '') !== false;

        return self::$compiled[$pattern] = $ok ? $regex : false;
    }
}
