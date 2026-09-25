<?php

declare(strict_types=1);

namespace TgJobParser\Letter;

use TgJobParser\Profile\Matcher;

/**
 * Проверка письма перед показом (ТЗ 3.5 «Запрещено»): длина, клише, выдуманные цифры, контакты.
 * Непрошедшее проверку AI-письмо заменяется шаблонным — пользователь не увидит «галлюцинацию».
 */
final class LetterGuard
{
    /**
     * @param array<string, int> $maxWords kind → лимит слов
     * @param list<string> $forbiddenPhrases
     */
    public function __construct(
        private readonly array $maxWords,
        private readonly array $forbiddenPhrases,
    ) {
    }

    /** @throws LetterGenerationException */
    public function check(string $text, string $kind, LetterRequest $request): string
    {
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)));
        if ($text === '') {
            throw new LetterGenerationException('Пустое письмо');
        }
        $normalized = Matcher::normalize($text);
        foreach ($this->forbiddenPhrases as $phrase) {
            if (Matcher::matchesPattern($phrase, $normalized)) {
                throw new LetterGenerationException('Запрещённая формулировка: ' . $phrase);
            }
        }
        $limit = $this->maxWords[$kind] ?? 200;
        $words = self::wordCount($this->withoutSignature($text, $request));
        if ($words > $limit) {
            throw new LetterGenerationException("Слишком длинное письмо: {$words} слов > {$limit}");
        }
        if ($kind === 'ai') {
            $unknown = $this->unknownNumbers($text, $request);
            if ($unknown !== []) {
                throw new LetterGenerationException('Цифры не из резюме: ' . implode(', ', $unknown));
            }
        }

        return $this->ensureContacts($text, $request);
    }

    public static function wordCount(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-’\']*/u', $text);
    }

    /**
     * Цифры в письме должны встречаться в фактах резюме, контактах или тексте вакансии.
     *
     * @return list<string>
     */
    private function unknownNumbers(string $text, LetterRequest $request): array
    {
        $allowedSource = $request->vacancyText . ' ' . implode(' ', $request->profile->candidate);
        foreach ($request->profile->experience as $case) {
            $allowedSource .= ' ' . ($case['result_ru'] ?? '') . ' ' . ($case['result_en'] ?? '');
        }
        $allowedSource .= ' ' . ($request->profile->petProject['text_ru'] ?? '') . ' ' . ($request->profile->petProject['text_en'] ?? '');
        $allowed = array_flip(self::numbers($allowedSource));
        $unknown = [];
        foreach (self::numbers($text) as $number) {
            // Мелкие числа («2–3 кейса», «1 месяц») не считаем фактами
            if ((float) $number <= 3 || isset($allowed[$number])) {
                continue;
            }
            $unknown[] = $number;
        }

        return array_values(array_unique($unknown));
    }

    /** @return list<string> */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d[\d\s,.]*\d|\d/u', $text, $m);

        return array_map(static fn (string $n): string => (string) preg_replace('/[\s,.]/u', '', $n), $m[0]);
    }

    private function withoutSignature(string $text, LetterRequest $request): string
    {
        foreach (['name', 'phone', 'telegram'] as $key) {
            $value = $request->profile->contact($key);
            if ($value !== '') {
                $text = str_replace($value, '', $text);
            }
        }

        return $text;
    }

    private function ensureContacts(string $text, LetterRequest $request): string
    {
        $profile = $request->profile;
        $missing = array_filter(
            [$profile->contact('phone'), $profile->contact('telegram')],
            static fn (string $v): bool => $v !== '' && !str_contains($text, $v),
        );
        if ($missing === []) {
            return $text;
        }
        $name = str_contains($text, $profile->contact('name')) ? '' : $profile->contact('name') . "\n";

        return rtrim($text) . "\n\n" . $name . implode(', ', array_filter([$profile->contact('phone'), $profile->contact('telegram')]));
    }
}
