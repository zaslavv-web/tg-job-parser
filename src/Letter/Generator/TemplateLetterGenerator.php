<?php

declare(strict_types=1);

namespace TgJobParser\Letter\Generator;

use TgJobParser\Letter\LetterGuard;
use TgJobParser\Letter\LetterRequest;
use TgJobParser\Kernel\Config;

/**
 * Шаблонный режим (без API): подстановка кейсов по ключевым словам вакансии.
 * Тексты фраз — в templates/letters/{ru,en}.php, их можно править без кода.
 */
final class TemplateLetterGenerator implements LetterGeneratorInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function kind(): string
    {
        return 'template';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function generate(LetterRequest $request): string
    {
        // Укладываемся в лимит «30 секунд чтения»: сначала убираем третий кейс, затем пет-проект
        $limit = (int) $this->config->get('letters.max_words.template', 130);
        $cases = $request->cases;
        $withPet = $request->mentionPetProject;
        while (true) {
            $text = $this->compose($request, $cases, $withPet);
            if (LetterGuard::wordCount($text) <= $limit || (count($cases) <= 2 && !$withPet)) {
                return $text;
            }
            if (count($cases) > 2) {
                array_pop($cases);
            } else {
                $withPet = false;
            }
        }
    }

    /** @param list<array<string, mixed>> $cases */
    private function compose(LetterRequest $request, array $cases, bool $withPet): string
    {
        $lang = $request->language === 'en' ? 'en' : 'ru';
        $phrases = require $this->config->get('paths.templates') . "/letters/{$lang}.php";
        $profile = $request->profile;

        $role = $request->role ?: $phrases['default_role'];
        $position = $request->company
            ? strtr($phrases['position_with_company'], ['{role}' => $role, '{company}' => $request->company])
            : strtr($phrases['position'], ['{role}' => $role]);

        $domain = $request->domains[0] ?? null;
        $interest = $domain !== null && isset($phrases['domain_interest'][$domain])
            ? $phrases['domain_interest'][$domain]
            : $phrases['generic_interest'];

        $resultKey = $lang === 'en' ? 'result_en' : 'result_ru';
        $caseLines = array_map(
            static fn (array $case): string => strtr($phrases['case_line'], ['{project}' => $case['project'], '{result}' => $case[$resultKey] ?? $case['result_ru']]),
            $cases,
        );

        $parts = [
            $phrases['greeting'],
            trim($position . ' ' . $interest),
            $phrases['cases_intro'] . "\n" . implode("\n", $caseLines),
        ];
        if ($withPet) {
            $parts[] = (string) ($profile->petProject[$lang === 'en' ? 'text_en' : 'text_ru'] ?? '');
        }
        // Требования пользователя вида «+ текст» вставляются в письмо дословно
        foreach ($request->requirements as $requirement) {
            if (str_starts_with(trim($requirement), '+')) {
                $parts[] = trim(ltrim(trim($requirement), '+'));
            }
        }
        $parts[] = $phrases['call_to_action'];
        $parts[] = $profile->contact('name') . "\n" . $profile->contact('phone') . ', ' . $profile->contact('telegram');

        return implode("\n\n", array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }
}
